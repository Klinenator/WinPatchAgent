using PatchAgent.Service.Models;

namespace PatchAgent.Service.Abstractions;

public interface IAgentIdentityManager
{
    Task EnsureRegisteredAsync(AgentState state, CancellationToken cancellationToken);
}
