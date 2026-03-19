namespace PatchAgent.Service.Models;

public sealed class AgentState
{
    public string DeviceId { get; set; } = Guid.NewGuid().ToString();

    public bool IsRegistered { get; set; }

    public string? ServerAssignedAgentId { get; set; }

    public string? AgentToken { get; set; }

    public DateTimeOffset? RegisteredAtUtc { get; set; }

    public int? ServerHeartbeatIntervalSeconds { get; set; }

    public int? ServerInventoryIntervalSeconds { get; set; }

    public int? ServerJobPollIntervalSeconds { get; set; }

    public DateTimeOffset? LastHeartbeatAtUtc { get; set; }

    public DateTimeOffset? LastInventoryAtUtc { get; set; }

    public DateTimeOffset? LastJobPollAtUtc { get; set; }

    public JobExecutionState? CurrentJob { get; set; }
}
