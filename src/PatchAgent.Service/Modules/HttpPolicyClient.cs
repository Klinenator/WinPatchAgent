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
                Family = DetectOsFamily(),
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
                PendingReboot = snapshot.PendingReboot,
                InstalledPatches = snapshot.InstalledWindowsPatches
                    .Select(patch => new InventoryInstalledPatch
                    {
                        Kb = patch.Kb,
                        Title = patch.Title,
                        InstalledAt = patch.InstalledAt
                    })
                    .ToList(),
                InstalledPatchesCount = snapshot.InstalledWindowsPatches.Count,
                AvailablePatches = snapshot.AvailableWindowsPatches
                    .Select(update => new InventoryAvailablePatch
                    {
                        UpdateId = update.UpdateId,
                        Title = update.Title
                    })
                    .ToList(),
                AvailablePatchesCount = snapshot.AvailableWindowsPatches.Count
            },
            Hardware = new InventoryHardware
            {
                Hostname = snapshot.Hostname,
                DomainOrWorkgroup = snapshot.DomainOrWorkgroup,
                FreeDiskMb = snapshot.FreeDiskMb
            },
            Linux = OperatingSystem.IsLinux()
                ? new InventoryUploadLinux
                {
                    DistroId = snapshot.LinuxDistroId,
                    DistroVersionId = snapshot.LinuxDistroVersionId,
                    KernelVersion = snapshot.LinuxKernelVersion,
                    AptAvailable = snapshot.AptAvailable,
                    PackageUpdatesAvailable = snapshot.LinuxPackageUpdatesAvailable,
                    AvailablePackages = snapshot.LinuxAvailablePackages.ToList(),
                    AvailablePackagesCount = snapshot.LinuxAvailablePackages.Count
                }
                : null,
            MacOs = OperatingSystem.IsMacOS()
                ? new InventoryUploadMacOs
                {
                    ProductVersion = snapshot.MacOsProductVersion,
                    BuildVersion = snapshot.MacOsBuildVersion,
                    SoftwareUpdateAvailable = snapshot.MacSoftwareUpdateAvailable,
                    AvailableUpdateLabels = snapshot.MacAvailableUpdateLabels.ToList(),
                    AvailableUpdatesCount = snapshot.MacAvailableUpdatesCount
                }
                : null
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
            MaintenanceWindowEndUtc = response.Job.Policy?.MaintenanceWindow?.End,
            StubDurationSeconds = ReadStubDurationSeconds(response.Job.Payload),
            SimulatedOutcome = ReadSimulatedOutcome(response.Job.Payload),
            SimulatedRebootRequired = ReadSimulatedRebootRequired(response.Job.Payload),
            AptUpgradeAll = ReadAptUpgradeAll(response.Job.Payload),
            AptPackages = ReadAptPackages(response.Job.Payload),
            WindowsInstallAll = ReadWindowsInstallAll(response.Job.Payload),
            WindowsKbIds = ReadWindowsKbIds(response.Job.Payload),
            MacOsInstallAll = ReadMacOsInstallAll(response.Job.Payload),
            MacOsUpdateLabels = ReadMacOsUpdateLabels(response.Job.Payload),
            WindowsPowerShellScript = ReadWindowsPowerShellScript(response.Job.Payload),
            WindowsPowerShellScriptUrl = ReadWindowsPowerShellScriptUrl(response.Job.Payload)
        };
    }

    public async Task AcknowledgeJobAsync(
        AgentState state,
        JobExecutionState job,
        string ack,
        string? reason,
        CancellationToken cancellationToken)
    {
        var request = new JobAckRequest
        {
            AgentId = GetAgentId(state),
            DeviceId = state.DeviceId,
            Ack = ack,
            Reason = reason,
            AcknowledgedAt = DateTimeOffset.UtcNow
        };

        await PostAsync<JobAckRequest, AcceptedResponse>(
            $"v1/agents/jobs/{job.JobId}/ack",
            request,
            state,
            cancellationToken);
    }

    public async Task CompleteJobAsync(
        AgentState state,
        JobExecutionState job,
        JobCompletionReport report,
        CancellationToken cancellationToken)
    {
        var request = new JobCompletionRequest
        {
            AgentId = GetAgentId(state),
            DeviceId = state.DeviceId,
            CompletedAt = DateTimeOffset.UtcNow,
            FinalState = report.FinalState,
            Result = new JobCompletionResult
            {
                InstallResult = report.InstallResult,
                RebootRequired = report.RebootRequired,
                RebootPerformed = report.RebootPerformed,
                PostRebootValidation = report.PostRebootValidation
            },
            Error = report.ErrorCode is null && report.ErrorMessage is null && report.Retryable is null
                ? null
                : new JobCompletionError
                {
                    Code = report.ErrorCode,
                    Message = report.ErrorMessage,
                    Retryable = report.Retryable
                }
        };

        await PostAsync<JobCompletionRequest, AcceptedResponse>(
            $"v1/agents/jobs/{job.JobId}/complete",
            request,
            state,
            cancellationToken);
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

    private static string DetectOsFamily()
    {
        if (OperatingSystem.IsWindows())
        {
            return "windows";
        }

        if (OperatingSystem.IsLinux())
        {
            return "linux";
        }

        if (OperatingSystem.IsMacOS())
        {
            return "mac";
        }

        return Environment.OSVersion.Platform.ToString().ToLowerInvariant();
    }

    private static string ReadSimulatedOutcome(JsonElement? payload)
    {
        if (TryGetSimulationValue(payload, "result", out var value) && value is { ValueKind: JsonValueKind.String })
        {
            var parsed = value.Value.GetString();
            if (!string.IsNullOrWhiteSpace(parsed))
            {
                return parsed.Trim().ToLowerInvariant();
            }
        }

        return "success";
    }

    private static int? ReadStubDurationSeconds(JsonElement? payload)
    {
        if (TryGetSimulationValue(payload, "duration_seconds", out var value) && value is { ValueKind: JsonValueKind.Number })
        {
            return value.Value.TryGetInt32(out var seconds) ? seconds : null;
        }

        return null;
    }

    private static bool ReadSimulatedRebootRequired(JsonElement? payload)
    {
        if (TryGetSimulationValue(payload, "reboot_required", out var value) && value is { ValueKind: JsonValueKind.True or JsonValueKind.False })
        {
            return value.Value.GetBoolean();
        }

        return false;
    }

    private static bool TryGetSimulationValue(
        JsonElement? payload,
        string key,
        out JsonElement? value)
    {
        value = null;

        if (payload is not { ValueKind: JsonValueKind.Object } payloadObject)
        {
            return false;
        }

        if (!payloadObject.TryGetProperty("simulation", out var simulation) || simulation.ValueKind != JsonValueKind.Object)
        {
            return false;
        }

        if (!simulation.TryGetProperty(key, out var child))
        {
            return false;
        }

        value = child;
        return true;
    }

    private static bool ReadAptUpgradeAll(JsonElement? payload)
    {
        if (TryGetAptValue(payload, "upgrade_all", out var value) && value is { ValueKind: JsonValueKind.True or JsonValueKind.False })
        {
            return value.Value.GetBoolean();
        }

        return false;
    }

    private static List<string> ReadAptPackages(JsonElement? payload)
    {
        if (!TryGetAptValue(payload, "packages", out var value) || value is not { ValueKind: JsonValueKind.Array })
        {
            return [];
        }

        var packages = new List<string>();
        foreach (var item in value.Value.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.String)
            {
                continue;
            }

            var packageName = item.GetString();
            if (!string.IsNullOrWhiteSpace(packageName))
            {
                packages.Add(packageName.Trim());
            }
        }

        return packages;
    }

    private static bool TryGetAptValue(
        JsonElement? payload,
        string key,
        out JsonElement? value)
    {
        value = null;

        if (payload is not { ValueKind: JsonValueKind.Object } payloadObject)
        {
            return false;
        }

        if (!payloadObject.TryGetProperty("apt", out var aptSection) || aptSection.ValueKind != JsonValueKind.Object)
        {
            return false;
        }

        if (!aptSection.TryGetProperty(key, out var child))
        {
            return false;
        }

        value = child;
        return true;
    }

    private static bool ReadWindowsInstallAll(JsonElement? payload)
    {
        if (TryGetWindowsUpdateValue(payload, "install_all", out var value) && value is { ValueKind: JsonValueKind.True or JsonValueKind.False })
        {
            return value.Value.GetBoolean();
        }

        return false;
    }

    private static string ReadWindowsPowerShellScript(JsonElement? payload)
    {
        if (TryGetWindowsScriptValue(payload, "script", out var value) && value is { ValueKind: JsonValueKind.String })
        {
            var script = value.Value.GetString();
            if (!string.IsNullOrWhiteSpace(script))
            {
                return script.Trim();
            }
        }

        return string.Empty;
    }

    private static string ReadWindowsPowerShellScriptUrl(JsonElement? payload)
    {
        if (TryGetWindowsScriptValue(payload, "script_url", out var value) && value is { ValueKind: JsonValueKind.String })
        {
            var scriptUrl = value.Value.GetString();
            if (!string.IsNullOrWhiteSpace(scriptUrl))
            {
                return scriptUrl.Trim();
            }
        }

        if (TryGetWindowsScriptValue(payload, "url", out value) && value is { ValueKind: JsonValueKind.String })
        {
            var scriptUrl = value.Value.GetString();
            if (!string.IsNullOrWhiteSpace(scriptUrl))
            {
                return scriptUrl.Trim();
            }
        }

        return string.Empty;
    }

    private static List<string> ReadWindowsKbIds(JsonElement? payload)
    {
        var kbSet = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

        AddKbValuesFromArrayValue(payload, "kbs", kbSet);
        AddKbValuesFromArrayValue(payload, "kb_ids", kbSet);
        AddKbValuesFromUpdates(payload, kbSet);

        return kbSet
            .Select(NormalizeKb)
            .Where(static kb => !string.IsNullOrWhiteSpace(kb))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();
    }

    private static bool ReadMacOsInstallAll(JsonElement? payload)
    {
        if (TryGetMacOsUpdateValue(payload, "install_all", out var value) && value is { ValueKind: JsonValueKind.True or JsonValueKind.False })
        {
            return value.Value.GetBoolean();
        }

        return false;
    }

    private static List<string> ReadMacOsUpdateLabels(JsonElement? payload)
    {
        var labelSet = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

        AddMacLabelValuesFromArrayValue(payload, "labels", labelSet);
        AddMacLabelValuesFromArrayValue(payload, "update_labels", labelSet);
        AddMacLabelValuesFromArrayValue(payload, "packages", labelSet);
        AddMacLabelValuesFromUpdates(payload, labelSet);

        return labelSet
            .Select(static label => label.Trim())
            .Where(static label => !string.IsNullOrWhiteSpace(label))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();
    }

    private static void AddKbValuesFromArrayValue(
        JsonElement? payload,
        string key,
        ISet<string> kbSet)
    {
        if (!TryGetWindowsUpdateValue(payload, key, out var value) || value is not { ValueKind: JsonValueKind.Array })
        {
            return;
        }

        foreach (var item in value.Value.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.String)
            {
                continue;
            }

            var kb = item.GetString();
            if (!string.IsNullOrWhiteSpace(kb))
            {
                kbSet.Add(kb.Trim());
            }
        }
    }

    private static void AddKbValuesFromUpdates(JsonElement? payload, ISet<string> kbSet)
    {
        if (payload is not { ValueKind: JsonValueKind.Object } payloadObject)
        {
            return;
        }

        JsonElement updatesValue;
        if (TryGetWindowsUpdateValue(payload, "updates", out var nestedUpdates)
            && nestedUpdates is { ValueKind: JsonValueKind.Array })
        {
            updatesValue = nestedUpdates.Value;
        }
        else if (payloadObject.TryGetProperty("updates", out var topLevelUpdates)
                 && topLevelUpdates.ValueKind == JsonValueKind.Array)
        {
            updatesValue = topLevelUpdates;
        }
        else
        {
            return;
        }

        foreach (var update in updatesValue.EnumerateArray())
        {
            if (update.ValueKind != JsonValueKind.Object)
            {
                continue;
            }

            if (update.TryGetProperty("kb", out var kbValue) && kbValue.ValueKind == JsonValueKind.String)
            {
                var kb = kbValue.GetString();
                if (!string.IsNullOrWhiteSpace(kb))
                {
                    kbSet.Add(kb.Trim());
                }
            }
        }
    }

    private static void AddMacLabelValuesFromArrayValue(
        JsonElement? payload,
        string key,
        ISet<string> labelSet)
    {
        if (!TryGetMacOsUpdateValue(payload, key, out var value) || value is not { ValueKind: JsonValueKind.Array })
        {
            return;
        }

        foreach (var item in value.Value.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.String)
            {
                continue;
            }

            var label = item.GetString();
            if (!string.IsNullOrWhiteSpace(label))
            {
                labelSet.Add(label.Trim());
            }
        }
    }

    private static void AddMacLabelValuesFromUpdates(JsonElement? payload, ISet<string> labelSet)
    {
        if (payload is not { ValueKind: JsonValueKind.Object } payloadObject)
        {
            return;
        }

        JsonElement updatesValue;
        if (TryGetMacOsUpdateValue(payload, "updates", out var nestedUpdates)
            && nestedUpdates is { ValueKind: JsonValueKind.Array })
        {
            updatesValue = nestedUpdates.Value;
        }
        else if (payloadObject.TryGetProperty("updates", out var topLevelUpdates)
                 && topLevelUpdates.ValueKind == JsonValueKind.Array)
        {
            updatesValue = topLevelUpdates;
        }
        else
        {
            return;
        }

        foreach (var update in updatesValue.EnumerateArray())
        {
            if (update.ValueKind != JsonValueKind.Object)
            {
                continue;
            }

            foreach (var propertyName in new[] { "label", "name", "id", "package" })
            {
                if (!update.TryGetProperty(propertyName, out var labelValue) || labelValue.ValueKind != JsonValueKind.String)
                {
                    continue;
                }

                var label = labelValue.GetString();
                if (!string.IsNullOrWhiteSpace(label))
                {
                    labelSet.Add(label.Trim());
                }
            }
        }
    }

    private static bool TryGetWindowsUpdateValue(
        JsonElement? payload,
        string key,
        out JsonElement? value)
    {
        value = null;

        if (payload is not { ValueKind: JsonValueKind.Object } payloadObject)
        {
            return false;
        }

        if (!payloadObject.TryGetProperty("windows_update", out var windowsUpdateSection)
            || windowsUpdateSection.ValueKind != JsonValueKind.Object)
        {
            return false;
        }

        if (!windowsUpdateSection.TryGetProperty(key, out var child))
        {
            return false;
        }

        value = child;
        return true;
    }

    private static bool TryGetWindowsScriptValue(
        JsonElement? payload,
        string key,
        out JsonElement? value)
    {
        value = null;

        if (payload is not { ValueKind: JsonValueKind.Object } payloadObject)
        {
            return false;
        }

        JsonElement scriptSection;
        if (payloadObject.TryGetProperty("windows_script", out var windowsScriptSection)
            && windowsScriptSection.ValueKind == JsonValueKind.Object)
        {
            scriptSection = windowsScriptSection;
        }
        else if (payloadObject.TryGetProperty("powershell", out var powershellSection)
                 && powershellSection.ValueKind == JsonValueKind.Object)
        {
            scriptSection = powershellSection;
        }
        else
        {
            return false;
        }

        if (!scriptSection.TryGetProperty(key, out var child))
        {
            return false;
        }

        value = child;
        return true;
    }

    private static bool TryGetMacOsUpdateValue(
        JsonElement? payload,
        string key,
        out JsonElement? value)
    {
        value = null;

        if (payload is not { ValueKind: JsonValueKind.Object } payloadObject)
        {
            return false;
        }

        JsonElement macOsSection;
        if (payloadObject.TryGetProperty("macos_update", out var macOsUpdateSection)
            && macOsUpdateSection.ValueKind == JsonValueKind.Object)
        {
            macOsSection = macOsUpdateSection;
        }
        else if (payloadObject.TryGetProperty("mac_update", out var macUpdateSection)
                 && macUpdateSection.ValueKind == JsonValueKind.Object)
        {
            macOsSection = macUpdateSection;
        }
        else
        {
            return false;
        }

        if (!macOsSection.TryGetProperty(key, out var child))
        {
            return false;
        }

        value = child;
        return true;
    }

    private static string NormalizeKb(string kb)
    {
        var normalized = kb.Trim().ToUpperInvariant();
        if (normalized == string.Empty)
        {
            return string.Empty;
        }

        if (!normalized.StartsWith("KB", StringComparison.Ordinal))
        {
            normalized = "KB" + normalized;
        }

        return normalized;
    }
}
