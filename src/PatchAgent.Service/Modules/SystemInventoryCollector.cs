using Microsoft.Extensions.Logging;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Modules;

public sealed class SystemInventoryCollector : IInventoryCollector
{
    private readonly ILogger<SystemInventoryCollector> _logger;
    private readonly IPathProvider _pathProvider;

    public SystemInventoryCollector(
        ILogger<SystemInventoryCollector> logger,
        IPathProvider pathProvider)
    {
        _logger = logger;
        _pathProvider = pathProvider;
    }

    public Task<InventorySnapshot> CollectAsync(AgentState state, CancellationToken cancellationToken)
    {
        var rootPath = Path.GetPathRoot(_pathProvider.RootPath);
        long? freeDiskMb = null;

        if (!string.IsNullOrWhiteSpace(rootPath))
        {
            var driveInfo = new DriveInfo(rootPath);
            if (driveInfo.IsReady)
            {
                freeDiskMb = driveInfo.AvailableFreeSpace / (1024 * 1024);
            }
        }

        var snapshot = new InventorySnapshot
        {
            CollectedAtUtc = DateTimeOffset.UtcNow,
            FreeDiskMb = freeDiskMb
        };

        _logger.LogInformation(
            "Collected inventory snapshot for device {DeviceId} on host {Hostname}",
            state.DeviceId,
            snapshot.Hostname);

        return Task.FromResult(snapshot);
    }
}
