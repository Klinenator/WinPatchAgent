using PatchAgent.Service.Models;

namespace PatchAgent.Service.Abstractions;

public interface IInventoryCollector
{
    Task<InventorySnapshot> CollectAsync(AgentState state, CancellationToken cancellationToken);
}
