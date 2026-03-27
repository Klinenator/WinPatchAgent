namespace PatchAgent.Service.Models;

public sealed class JobExecutionState
{
    public string JobId { get; set; } = string.Empty;

    public string JobType { get; set; } = string.Empty;

    public string CorrelationId { get; set; } = string.Empty;

    public string State { get; set; } = "Assigned";

    public DateTimeOffset StateChangedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset? ExecutionStartedAtUtc { get; set; }

    public DateTimeOffset? ExecutionDueAtUtc { get; set; }

    public int? PercentComplete { get; set; }

    public bool ResumeRequired { get; set; }

    public int StubDurationSeconds { get; set; } = 20;

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

    public string AgentSelfUpdateRepoUrl { get; set; } = string.Empty;

    public string AgentSelfUpdateRepoRef { get; set; } = string.Empty;

    public string AgentSelfUpdatePackageUrl { get; set; } = string.Empty;

    public string AgentSelfUpdateWindowsInstallMode { get; set; } = string.Empty;

    public string SoftwareInstallManager { get; set; } = string.Empty;

    public bool SoftwareInstallAllowUpdate { get; set; }

    public List<string> SoftwareInstallPackages { get; set; } = [];

    public string SoftwareSearchManager { get; set; } = string.Empty;

    public string SoftwareSearchQuery { get; set; } = string.Empty;

    public int SoftwareSearchLimit { get; set; } = 25;
}
