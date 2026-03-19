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

    Task<bool> PublishEventsAsync(
        AgentState state,
        IReadOnlyCollection<TelemetryEvent> events,
        CancellationToken cancellationToken);
}
