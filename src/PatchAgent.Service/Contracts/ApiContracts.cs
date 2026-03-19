namespace PatchAgent.Service.Contracts;

public sealed class RegisterAgentRequest
{
    public int RegistrationVersion { get; set; } = 1;

    public string? EnrollmentKey { get; set; }

    public RegisterDevice Device { get; set; } = new();

    public RegisterOs Os { get; set; } = new();

    public RegisterAgentInfo Agent { get; set; } = new();

    public List<string> Capabilities { get; set; } = [];
}

public sealed class RegisterDevice
{
    public string DeviceId { get; set; } = string.Empty;

    public string Hostname { get; set; } = string.Empty;

    public string Domain { get; set; } = string.Empty;
}

public sealed class RegisterOs
{
    public string Family { get; set; } = "windows";

    public string Description { get; set; } = string.Empty;

    public string Architecture { get; set; } = string.Empty;
}

public sealed class RegisterAgentInfo
{
    public string Version { get; set; } = string.Empty;

    public string Channel { get; set; } = string.Empty;
}

public sealed class RegisterAgentResponse
{
    public string? AgentRecordId { get; set; }

    public string? AgentToken { get; set; }

    public PollIntervals? Poll { get; set; }
}

public sealed class PollIntervals
{
    public int? HeartbeatSeconds { get; set; }

    public int? JobsSeconds { get; set; }

    public int? InventorySeconds { get; set; }
}

public sealed class HeartbeatRequest
{
    public string AgentId { get; set; } = string.Empty;

    public string DeviceId { get; set; } = string.Empty;

    public DateTimeOffset SentAt { get; set; }

    public string AgentVersion { get; set; } = string.Empty;

    public string ServiceState { get; set; } = "healthy";

    public HeartbeatSystemState SystemState { get; set; } = new();

    public HeartbeatCurrentJob? CurrentJob { get; set; }
}

public sealed class HeartbeatSystemState
{
    public DateTimeOffset? LastHeartbeatAt { get; set; }

    public DateTimeOffset? LastInventoryAt { get; set; }

    public bool PendingReboot { get; set; }
}

public sealed class HeartbeatCurrentJob
{
    public string JobId { get; set; } = string.Empty;

    public string State { get; set; } = string.Empty;

    public DateTimeOffset StateChangedAt { get; set; }

    public int? PercentComplete { get; set; }
}

public sealed class HeartbeatResponse
{
    public DateTimeOffset? ServerTime { get; set; }

    public PollIntervals? DesiredPollIntervals { get; set; }
}

public sealed class InventoryUploadRequest
{
    public string AgentId { get; set; } = string.Empty;

    public string DeviceId { get; set; } = string.Empty;

    public string Mode { get; set; } = "full";

    public DateTimeOffset CollectedAt { get; set; }

    public InventoryUploadOs Os { get; set; } = new();

    public InventoryWindowsUpdate WindowsUpdate { get; set; } = new();

    public InventoryHardware Hardware { get; set; } = new();

    public List<InventoryApplication> Applications { get; set; } = [];
}

public sealed class InventoryUploadOs
{
    public string Description { get; set; } = string.Empty;

    public string OsArchitecture { get; set; } = string.Empty;

    public string ProcessArchitecture { get; set; } = string.Empty;
}

public sealed class InventoryWindowsUpdate
{
    public bool PendingReboot { get; set; }
}

public sealed class InventoryHardware
{
    public string Hostname { get; set; } = string.Empty;

    public string DomainOrWorkgroup { get; set; } = string.Empty;

    public long? FreeDiskMb { get; set; }
}

public sealed class InventoryApplication
{
    public string Name { get; set; } = string.Empty;

    public string? Version { get; set; }
}

public sealed class FetchNextJobRequest
{
    public string AgentId { get; set; } = string.Empty;

    public string DeviceId { get; set; } = string.Empty;

    public DateTimeOffset RequestedAt { get; set; }

    public string? CurrentJobId { get; set; }

    public int SupportsConcurrency { get; set; } = 1;
}

public sealed class FetchNextJobResponse
{
    public FetchJobPayload? Job { get; set; }
}

public sealed class FetchJobPayload
{
    public string JobId { get; set; } = string.Empty;

    public string Type { get; set; } = string.Empty;

    public string CorrelationId { get; set; } = string.Empty;

    public FetchJobPolicy? Policy { get; set; }
}

public sealed class FetchJobPolicy
{
    public FetchMaintenanceWindow? MaintenanceWindow { get; set; }
}

public sealed class FetchMaintenanceWindow
{
    public DateTimeOffset? Start { get; set; }

    public DateTimeOffset? End { get; set; }
}

public sealed class TelemetryBatchRequest
{
    public string AgentId { get; set; } = string.Empty;

    public string DeviceId { get; set; } = string.Empty;

    public List<TelemetryBatchEvent> Events { get; set; } = [];
}

public sealed class TelemetryBatchEvent
{
    public string EventId { get; set; } = string.Empty;

    public DateTimeOffset Timestamp { get; set; }

    public string Type { get; set; } = string.Empty;

    public object? Payload { get; set; }
}

public sealed class AcceptedResponse
{
    public bool Accepted { get; set; } = true;

    public int? AcceptedCount { get; set; }
}
