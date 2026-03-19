using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Reflection;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Contracts;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Modules;

public sealed class HttpPolicyClient : IPolicyClient
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DictionaryKeyPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
    };

    private readonly HttpClient _httpClient;
    private readonly ILogger<HttpPolicyClient> _logger;
    private readonly AgentOptions _options;

    public HttpPolicyClient(
        HttpClient httpClient,
        ILogger<HttpPolicyClient> logger,
        IOptions<AgentOptions> options)
    {
        _httpClient = httpClient;
        _logger = logger;
        _options = options.Value;
    }

    public async Task RegisterAsync(AgentState state, CancellationToken cancellationToken)
    {
        var request = new RegisterAgentRequest
        {
            EnrollmentKey = string.IsNullOrWhiteSpace(_options.EnrollmentKey) ? null : _options.EnrollmentKey,
            Device = new RegisterDevice
            {
                DeviceId = state.DeviceId,
                Hostname = Environment.MachineName,
                Domain = Environment.UserDomainName
            },
            Os = new RegisterOs
            {
                Family = OperatingSystem.IsWindows() ? "windows" : Environment.OSVersion.Platform.ToString().ToLowerInvariant(),
                Description = System.Runtime.InteropServices.RuntimeInformation.OSDescription,
                Architecture = System.Runtime.InteropServices.RuntimeInformation.OSArchitecture.ToString()
            },
            Agent = new RegisterAgentInfo
            {
                Version = GetAgentVersion(),
                Channel = _options.AgentChannel
            },
            Capabilities =
            [
                "inventory",
                "heartbeat",
                "job_polling",
                "telemetry"
            ]
        };

        var response = await PostAsync<RegisterAgentRequest, RegisterAgentResponse>(
            "v1/agents/register",
            request,
            state: null,
            cancellationToken);

        if (string.IsNullOrWhiteSpace(response?.AgentRecordId))
        {
            throw new InvalidOperationException("Registration response did not include an agent_record_id.");
        }

        if (string.IsNullOrWhiteSpace(response.AgentToken))
        {
            throw new InvalidOperationException("Registration response did not include an agent_token.");
        }

        state.IsRegistered = true;
        state.ServerAssignedAgentId = response.AgentRecordId;
        state.AgentToken = response.AgentToken;
        state.RegisteredAtUtc = DateTimeOffset.UtcNow;
        ApplyPollIntervals(state, response.Poll);

        _logger.LogInformation(
            "Registered device {DeviceId} as agent {AgentId}",
            state.DeviceId,
            state.ServerAssignedAgentId);
    }

    public async Task SendHeartbeatAsync(AgentState state, CancellationToken cancellationToken)
    {
        var request = new HeartbeatRequest
        {
            AgentId = GetAgentId(state),
            DeviceId = state.DeviceId,
            SentAt = DateTimeOffset.UtcNow,
            AgentVersion = GetAgentVersion(),
            SystemState = new HeartbeatSystemState
            {
                LastHeartbeatAt = state.LastHeartbeatAtUtc,
                LastInventoryAt = state.LastInventoryAtUtc,
                PendingReboot = false
            },
            CurrentJob = state.CurrentJob is null
                ? null
                : new HeartbeatCurrentJob
                {
                    JobId = state.CurrentJob.JobId,
                    State = state.CurrentJob.State,
                    StateChangedAt = state.CurrentJob.StateChangedAtUtc,
                    PercentComplete = state.CurrentJob.PercentComplete
                }
        };

        var response = await PostAsync<HeartbeatRequest, HeartbeatResponse>(
            "v1/agents/heartbeat",
            request,
            state,
            cancellationToken);

        ApplyPollIntervals(state, response?.DesiredPollIntervals);
    }

    public async Task SendInventoryAsync(
        AgentState state,
        InventorySnapshot snapshot,
        CancellationToken cancellationToken)
    {
        var request = new InventoryUploadRequest
        {
            AgentId = GetAgentId(state),
            DeviceId = state.DeviceId,
            CollectedAt = snapshot.CollectedAtUtc,
            Os = new InventoryUploadOs
            {
                Description = snapshot.OsDescription,
                OsArchitecture = snapshot.OsArchitecture,
                ProcessArchitecture = snapshot.ProcessArchitecture
            },
            WindowsUpdate = new InventoryWindowsUpdate
            {
                PendingReboot = snapshot.PendingReboot
            },
            Hardware = new InventoryHardware
            {
                Hostname = snapshot.Hostname,
                DomainOrWorkgroup = snapshot.DomainOrWorkgroup,
                FreeDiskMb = snapshot.FreeDiskMb
            }
        };

        await PostAsync<InventoryUploadRequest, AcceptedResponse>(
            "v1/agents/inventory",
            request,
            state,
            cancellationToken);
    }

    public async Task<JobAssignment?> FetchNextJobAsync(AgentState state, CancellationToken cancellationToken)
    {
        var request = new FetchNextJobRequest
        {
            AgentId = GetAgentId(state),
            DeviceId = state.DeviceId,
            RequestedAt = DateTimeOffset.UtcNow,
            CurrentJobId = state.CurrentJob?.JobId
        };

        var response = await PostAsync<FetchNextJobRequest, FetchNextJobResponse>(
            "v1/agents/jobs/next",
            request,
            state,
            cancellationToken);

        if (response?.Job is null)
        {
            return null;
        }

        return new JobAssignment
        {
            JobId = response.Job.JobId,
            JobType = response.Job.Type,
            CorrelationId = response.Job.CorrelationId,
            MaintenanceWindowStartUtc = response.Job.Policy?.MaintenanceWindow?.Start,
            MaintenanceWindowEndUtc = response.Job.Policy?.MaintenanceWindow?.End
        };
    }

    public async Task<bool> PublishEventsAsync(
        AgentState state,
        IReadOnlyCollection<TelemetryEvent> events,
        CancellationToken cancellationToken)
    {
        var request = new TelemetryBatchRequest
        {
            AgentId = GetAgentId(state),
            DeviceId = state.DeviceId,
            Events = events.Select(ToBatchEvent).ToList()
        };

        var response = await PostAsync<TelemetryBatchRequest, AcceptedResponse>(
            "v1/agents/job-events",
            request,
            state,
            cancellationToken);

        return response?.Accepted ?? true;
    }

    private async Task<TResponse?> PostAsync<TRequest, TResponse>(
        string relativePath,
        TRequest payload,
        AgentState? state,
        CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, relativePath)
        {
            Content = JsonContent.Create(payload, options: JsonOptions)
        };

        if (!string.IsNullOrWhiteSpace(state?.AgentToken))
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", state.AgentToken);
        }

        using var response = await _httpClient.SendAsync(request, cancellationToken);

        if (response.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden)
        {
            if (state is not null)
            {
                ResetRegistration(state);
            }

            throw new UnauthorizedAccessException(
                $"Backend rejected agent credentials for {relativePath} with status code {(int)response.StatusCode}.");
        }

        var responseBody = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            throw new HttpRequestException(
                $"Backend call to {relativePath} failed with {(int)response.StatusCode}: {responseBody}",
                null,
                response.StatusCode);
        }

        if (string.IsNullOrWhiteSpace(responseBody))
        {
            return default;
        }

        return JsonSerializer.Deserialize<TResponse>(responseBody, JsonOptions);
    }

    private static TelemetryBatchEvent ToBatchEvent(TelemetryEvent telemetryEvent)
    {
        object? payload;

        try
        {
            payload = JsonSerializer.Deserialize<JsonElement>(telemetryEvent.PayloadJson, JsonOptions);
        }
        catch (JsonException)
        {
            payload = new { raw_payload = telemetryEvent.PayloadJson };
        }

        return new TelemetryBatchEvent
        {
            EventId = telemetryEvent.EventId,
            Timestamp = telemetryEvent.TimestampUtc,
            Type = telemetryEvent.EventType,
            Payload = payload
        };
    }

    private static string GetAgentVersion()
    {
        return Assembly.GetExecutingAssembly().GetName().Version?.ToString() ?? "0.1.0";
    }

    private static string GetAgentId(AgentState state)
    {
        return state.ServerAssignedAgentId ?? state.DeviceId;
    }

    private static void ResetRegistration(AgentState state)
    {
        state.IsRegistered = false;
        state.ServerAssignedAgentId = null;
        state.AgentToken = null;
    }

    private static void ApplyPollIntervals(AgentState state, PollIntervals? poll)
    {
        if (poll is null)
        {
            return;
        }

        state.ServerHeartbeatIntervalSeconds = poll.HeartbeatSeconds;
        state.ServerJobPollIntervalSeconds = poll.JobsSeconds;
        state.ServerInventoryIntervalSeconds = poll.InventorySeconds;
    }
}
