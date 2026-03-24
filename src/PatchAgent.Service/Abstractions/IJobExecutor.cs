using PatchAgent.Service.Models;

namespace PatchAgent.Service.Abstractions;

public interface IJobExecutor
{
    Task<bool> TryAdvanceAsync(AgentState state, CancellationToken cancellationToken);
}
