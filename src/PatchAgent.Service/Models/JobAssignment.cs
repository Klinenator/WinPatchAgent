namespace PatchAgent.Service.Models;

public sealed class JobAssignment
{
    public string JobId { get; set; } = string.Empty;

    public string JobType { get; set; } = string.Empty;

    public string CorrelationId { get; set; } = string.Empty;

    public DateTimeOffset? MaintenanceWindowStartUtc { get; set; }

    public DateTimeOffset? MaintenanceWindowEndUtc { get; set; }
}
