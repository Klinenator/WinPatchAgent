namespace PatchAgent.Service.Models;

public sealed class JobAssignment
{
    public string JobId { get; set; } = string.Empty;

    public string JobType { get; set; } = string.Empty;

    public string CorrelationId { get; set; } = string.Empty;

    public DateTimeOffset? MaintenanceWindowStartUtc { get; set; }

    public DateTimeOffset? MaintenanceWindowEndUtc { get; set; }

    public int? StubDurationSeconds { get; set; }

    public string SimulatedOutcome { get; set; } = "success";

    public bool SimulatedRebootRequired { get; set; }

    public bool AptUpgradeAll { get; set; }

    public List<string> AptPackages { get; set; } = [];
}
