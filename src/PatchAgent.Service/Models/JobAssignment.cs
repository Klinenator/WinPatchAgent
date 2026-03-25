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

    public bool WindowsInstallAll { get; set; }

    public List<string> WindowsKbIds { get; set; } = [];

    public bool MacOsInstallAll { get; set; }

    public List<string> MacOsUpdateLabels { get; set; } = [];

    public string WindowsPowerShellScript { get; set; } = string.Empty;

    public string WindowsPowerShellScriptUrl { get; set; } = string.Empty;

    public string MacShellScript { get; set; } = string.Empty;

    public string MacShellScriptUrl { get; set; } = string.Empty;
}
