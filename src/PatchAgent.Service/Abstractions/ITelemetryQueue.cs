using PatchAgent.Service.Models;

namespace PatchAgent.Service.Abstractions;

public interface ITelemetryQueue
{
    Task EnqueueAsync(TelemetryEvent telemetryEvent, CancellationToken cancellationToken);

    Task<IReadOnlyList<TelemetryEvent>> ReadPendingAsync(CancellationToken cancellationToken);

    Task ClearAsync(CancellationToken cancellationToken);
}
