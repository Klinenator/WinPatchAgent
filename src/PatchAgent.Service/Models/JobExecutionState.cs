namespace PatchAgent.Service.Models;

public sealed class JobExecutionState
{
    public string JobId { get; set; } = string.Empty;

    public string State { get; set; } = "Assigned";

    public DateTimeOffset StateChangedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    public int? PercentComplete { get; set; }

    public bool ResumeRequired { get; set; }
}
