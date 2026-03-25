namespace PatchAgent.Service.Configuration;

public sealed class AgentOptions
{
    public const string SectionName = "Agent";

    public string ServiceName { get; set; } = "PatchAgentSvc";

    public string BackendBaseUrl { get; set; } = "https://patch-api.example.local";

    public string EnrollmentKey { get; set; } = string.Empty;

    public string AgentChannel { get; set; } = "stable";

    public string StorageRoot { get; set; } = string.Empty;

    public int RequestTimeoutSeconds { get; set; } = 30;

    public int LoopDelaySeconds { get; set; } = 15;

    public int HeartbeatIntervalSeconds { get; set; } = 300;

    public int InventoryIntervalSeconds { get; set; } = 21600;

    public int JobPollIntervalSeconds { get; set; } = 120;

    public bool EnableStubJobExecution { get; set; } = true;

    public int StubJobDurationSeconds { get; set; } = 20;

    public bool EnableAptJobExecution { get; set; } = true;

    public bool EnableWindowsUpdateJobExecution { get; set; } = true;

    public int WindowsUpdateCommandTimeoutSeconds { get; set; } = 5400;

    public bool EnableWindowsPowerShellScriptExecution { get; set; } = true;

    public int WindowsPowerShellScriptCommandTimeoutSeconds { get; set; } = 3600;

    public bool EnableMacSoftwareUpdateJobExecution { get; set; } = true;

    public int MacSoftwareUpdateCommandTimeoutSeconds { get; set; } = 5400;

    public bool EnableMacShellScriptExecution { get; set; } = true;

    public int MacShellScriptCommandTimeoutSeconds { get; set; } = 3600;

    public bool AptUseSudoWhenNotRoot { get; set; } = true;

    public bool AptRunUpdateBeforeInstall { get; set; } = true;

    public int AptCommandTimeoutSeconds { get; set; } = 1800;
}
