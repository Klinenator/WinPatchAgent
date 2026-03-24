using PatchAgent.Service.Models;

namespace PatchAgent.Service.Abstractions;

public interface IPolicyClient
{
    Task RegisterAsync(AgentState state, CancellationToken cancellationToken);

    Task SendHeartbeatAsync(AgentState state, CancellationToken cancellationToken);

    Task SendInventoryAsync(
        AgentState state,
        InventorySnapshot snapshot,
        CancellationToken cancellationToken);

    Task<JobAssignment?> FetchNextJobAsync(AgentState state, CancellationToken cancellationToken);

    Task AcknowledgeJobAsync(
        AgentState state,
        JobExecutionState job,
        string ack,
        string? reason,
        CancellationToken cancellationToken);

    Task CompleteJobAsync(
        AgentState state,
        JobExecutionState job,
        JobCompletionReport report,
        CancellationToken cancellationToken);

    Task<bool> PublishEventsAsync(
        AgentState state,
        IReadOnlyCollection<TelemetryEvent> events,
        CancellationToken cancellationToken);
}
