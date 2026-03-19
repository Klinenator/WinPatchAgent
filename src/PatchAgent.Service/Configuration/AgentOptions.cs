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
}
