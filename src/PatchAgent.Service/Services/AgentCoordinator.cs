using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class AgentCoordinator
{
    private readonly ILogger<AgentCoordinator> _logger;
    private readonly AgentOptions _options;
    private readonly IPathProvider _pathProvider;
    private readonly ILocalStateStore _localStateStore;
    private readonly IAgentIdentityManager _identityManager;
    private readonly IInventoryCollector _inventoryCollector;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public AgentCoordinator(
        ILogger<AgentCoordinator> logger,
        IOptions<AgentOptions> options,
        IPathProvider pathProvider,
        ILocalStateStore localStateStore,
        IAgentIdentityManager identityManager,
        IInventoryCollector inventoryCollector,
        IPolicyClient policyClient,
        ITelemetryQueue telemetryQueue)
    {
        _logger = logger;
        _options = options.Value;
        _pathProvider = pathProvider;
        _localStateStore = localStateStore;
        _identityManager = identityManager;
        _inventoryCollector = inventoryCollector;
        _policyClient = policyClient;
        _telemetryQueue = telemetryQueue;
    }

    public async Task RunOnceAsync(CancellationToken cancellationToken)
    {
        await _pathProvider.EnsureCreatedAsync(cancellationToken);

        var state = await _localStateStore.LoadAsync(cancellationToken);

        await PersistIfChangedAsync(
            state,
            await ResumePendingWorkflowAsync(state, cancellationToken),
            cancellationToken);
        await PersistIfChangedAsync(
            state,
            await EnsureRegistrationAsync(state, cancellationToken),
            cancellationToken);
        await PersistIfChangedAsync(
            state,
            await MaybeSendHeartbeatAsync(state, cancellationToken),
            cancellationToken);
        await PersistIfChangedAsync(
            state,
            await MaybeCollectInventoryAsync(state, cancellationToken),
            cancellationToken);
        await PersistIfChangedAsync(
            state,
            await MaybePollForJobsAsync(state, cancellationToken),
            cancellationToken);
        await FlushTelemetryAsync(state, cancellationToken);

        await _localStateStore.SaveAsync(state, cancellationToken);
    }

    private async Task<bool> ResumePendingWorkflowAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (state.CurrentJob is null || !state.CurrentJob.ResumeRequired)
        {
            return false;
        }

        _logger.LogInformation(
            "Resuming pending workflow for job {JobId} in state {State}",
            state.CurrentJob.JobId,
            state.CurrentJob.State);

        state.CurrentJob.ResumeRequired = false;

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "post_reboot_resumed",
                new
                {
                    state.DeviceId,
                    state.CurrentJob.JobId,
                    state.CurrentJob.State
                }),
            cancellationToken);

        return true;
    }

    private async Task<bool> EnsureRegistrationAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (state.IsRegistered)
        {
            return false;
        }

        await _identityManager.EnsureRegisteredAsync(state, cancellationToken);

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "agent_registered",
                new
                {
                    state.DeviceId,
                    state.ServerAssignedAgentId,
                    state.RegisteredAtUtc
                }),
            cancellationToken);

        return true;
    }

    private async Task<bool> MaybeSendHeartbeatAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (!IsDue(
                state.LastHeartbeatAtUtc,
                state.ServerHeartbeatIntervalSeconds ?? _options.HeartbeatIntervalSeconds))
        {
            return false;
        }

        await _policyClient.SendHeartbeatAsync(state, cancellationToken);
        state.LastHeartbeatAtUtc = DateTimeOffset.UtcNow;

        return true;
    }

    private async Task<bool> MaybeCollectInventoryAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (!IsDue(
                state.LastInventoryAtUtc,
                state.ServerInventoryIntervalSeconds ?? _options.InventoryIntervalSeconds))
        {
            return false;
        }

        var snapshot = await _inventoryCollector.CollectAsync(state, cancellationToken);
        await _policyClient.SendInventoryAsync(state, snapshot, cancellationToken);

        state.LastInventoryAtUtc = snapshot.CollectedAtUtc;

        return true;
    }

    private async Task<bool> MaybePollForJobsAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (!IsDue(
                state.LastJobPollAtUtc,
                state.ServerJobPollIntervalSeconds ?? _options.JobPollIntervalSeconds))
        {
            return false;
        }

        if (state.CurrentJob is not null)
        {
            _logger.LogDebug(
                "Skipping job poll because job {JobId} is still active",
                state.CurrentJob.JobId);

            state.LastJobPollAtUtc = DateTimeOffset.UtcNow;
            return true;
        }

        var job = await _policyClient.FetchNextJobAsync(state, cancellationToken);
        state.LastJobPollAtUtc = DateTimeOffset.UtcNow;

        if (job is null)
        {
            return true;
        }

        state.CurrentJob = new JobExecutionState
        {
            JobId = job.JobId,
            State = "Assigned",
            StateChangedAtUtc = DateTimeOffset.UtcNow
        };

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "job_state_changed",
                new
                {
                    state.DeviceId,
                    job.JobId,
                    State = state.CurrentJob.State
                }),
            cancellationToken);

        return true;
    }

    private async Task FlushTelemetryAsync(AgentState state, CancellationToken cancellationToken)
    {
        var pendingEvents = await _telemetryQueue.ReadPendingAsync(cancellationToken);
        if (pendingEvents.Count == 0)
        {
            return;
        }

        var sent = await _policyClient.PublishEventsAsync(state, pendingEvents, cancellationToken);
        if (sent)
        {
            await _telemetryQueue.ClearAsync(cancellationToken);
        }
    }

    private static bool IsDue(DateTimeOffset? lastRunUtc, int intervalSeconds)
    {
        if (lastRunUtc is null)
        {
            return true;
        }

        return DateTimeOffset.UtcNow - lastRunUtc.Value >= TimeSpan.FromSeconds(intervalSeconds);
    }

    private async Task PersistIfChangedAsync(
        AgentState state,
        bool changed,
        CancellationToken cancellationToken)
    {
        if (!changed)
        {
            return;
        }

        await _localStateStore.SaveAsync(state, cancellationToken);
    }
}
