using PatchAgent.Service.Models;

namespace PatchAgent.Service.Abstractions;

public interface ILocalStateStore
{
    Task<AgentState> LoadAsync(CancellationToken cancellationToken);

    Task SaveAsync(AgentState state, CancellationToken cancellationToken);
}
