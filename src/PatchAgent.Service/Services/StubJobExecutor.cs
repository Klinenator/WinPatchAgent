using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class StubJobExecutor : IJobExecutor
{
    private readonly ILogger<StubJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public StubJobExecutor(
        ILogger<StubJobExecutor> logger,
        IOptions<AgentOptions> options,
        IPolicyClient policyClient,
        ITelemetryQueue telemetryQueue)
    {
        _logger = logger;
        _options = options.Value;
        _policyClient = policyClient;
        _telemetryQueue = telemetryQueue;
    }

    public async Task<bool> TryAdvanceAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (!_options.EnableStubJobExecution || state.CurrentJob is null)
        {
            return false;
        }

        return state.CurrentJob.State switch
        {
            "Assigned" => await StartInstallAsync(state, cancellationToken),
            "Installing" => await AdvanceInstallingAsync(state, cancellationToken),
            "Succeeded" or "Failed" => await ReportCompletionAsync(state, cancellationToken),
            _ => false
        };
    }

    private async Task<bool> StartInstallAsync(AgentState state, CancellationToken cancellationToken)
    {
        var currentJob = state.CurrentJob!;
        var now = DateTimeOffset.UtcNow;
        var durationSeconds = Math.Max(1, currentJob.StubDurationSeconds);

        currentJob.ExecutionStartedAtUtc = now;
        currentJob.ExecutionDueAtUtc = now.AddSeconds(durationSeconds);
        currentJob.State = "Installing";
        currentJob.StateChangedAtUtc = now;
        currentJob.PercentComplete = 10;

        _logger.LogInformation(
            "Starting stub execution for job {JobId} with simulated outcome {Outcome}",
            currentJob.JobId,
            currentJob.SimulatedOutcome);

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "install_started",
                new
                {
                    state.DeviceId,
                    currentJob.JobId,
                    currentJob.JobType,
                    currentJob.CorrelationId
                }),
            cancellationToken);

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "job_state_changed",
                new
                {
                    state.DeviceId,
                    currentJob.JobId,
                    State = currentJob.State
                }),
            cancellationToken);

        return true;
    }

    private async Task<bool> AdvanceInstallingAsync(AgentState state, CancellationToken cancellationToken)
    {
        var currentJob = state.CurrentJob!;
        var now = DateTimeOffset.UtcNow;

        currentJob.ExecutionStartedAtUtc ??= now;
        currentJob.ExecutionDueAtUtc ??= now.AddSeconds(Math.Max(1, currentJob.StubDurationSeconds));

        if (now < currentJob.ExecutionDueAtUtc.Value)
        {
            var totalSeconds = Math.Max(1, currentJob.StubDurationSeconds);
            var elapsedSeconds = Math.Max(0, (now - currentJob.ExecutionStartedAtUtc.Value).TotalSeconds);
            var ratio = Math.Clamp(elapsedSeconds / totalSeconds, 0, 0.99);
            var percent = Math.Clamp((int)Math.Round(10 + ratio * 80), 10, 90);

            if (currentJob.PercentComplete == percent)
            {
                return false;
            }

            currentJob.PercentComplete = percent;
            return true;
        }

        var failed = string.Equals(currentJob.SimulatedOutcome, "failed", StringComparison.OrdinalIgnoreCase);
        currentJob.State = failed ? "Failed" : "Succeeded";
        currentJob.StateChangedAtUtc = now;
        currentJob.PercentComplete = 100;

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "install_completed",
                new
                {
                    state.DeviceId,
                    currentJob.JobId,
                    FinalState = currentJob.State
                }),
            cancellationToken);

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "job_state_changed",
                new
                {
                    state.DeviceId,
                    currentJob.JobId,
                    State = currentJob.State
                }),
            cancellationToken);

        return true;
    }

    private async Task<bool> ReportCompletionAsync(AgentState state, CancellationToken cancellationToken)
    {
        var currentJob = state.CurrentJob!;
        var report = BuildCompletionReport(currentJob);

        await _policyClient.CompleteJobAsync(state, currentJob, report, cancellationToken);

        _logger.LogInformation(
            "Reported completion for job {JobId} with final state {FinalState}",
            currentJob.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private static JobCompletionReport BuildCompletionReport(JobExecutionState job)
    {
        var succeeded = string.Equals(job.State, "Succeeded", StringComparison.OrdinalIgnoreCase);

        if (succeeded)
        {
            return new JobCompletionReport
            {
                FinalState = "Succeeded",
                InstallResult = "success",
                RebootRequired = job.SimulatedRebootRequired,
                RebootPerformed = job.SimulatedRebootRequired,
                PostRebootValidation = job.SimulatedRebootRequired ? "passed" : "not_run"
            };
        }

        return new JobCompletionReport
        {
            FinalState = "Failed",
            InstallResult = "failed",
            RebootRequired = false,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            ErrorCode = "SIMULATED_INSTALL_FAILURE",
            ErrorMessage = "Stub executor was instructed to simulate a failed install.",
            Retryable = true
        };
    }
}
