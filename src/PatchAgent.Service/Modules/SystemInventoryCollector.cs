using Microsoft.Extensions.Logging;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;
using System.Runtime.InteropServices;

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

        if (OperatingSystem.IsLinux())
        {
            snapshot.PendingReboot = File.Exists("/var/run/reboot-required");
            snapshot.AptAvailable = File.Exists("/usr/bin/apt-get") || File.Exists("/usr/bin/apt");
            snapshot.LinuxKernelVersion = ReadLinuxKernelVersion();

            var osRelease = ParseOsRelease();
            if (osRelease.TryGetValue("ID", out var distroId))
            {
                snapshot.LinuxDistroId = distroId;
            }

            if (osRelease.TryGetValue("VERSION_ID", out var versionId))
            {
                snapshot.LinuxDistroVersionId = versionId;
            }
        }

        _logger.LogInformation(
            "Collected inventory snapshot for device {DeviceId} on host {Hostname}",
            state.DeviceId,
            snapshot.Hostname);

        return Task.FromResult(snapshot);
    }

    private static string? ReadLinuxKernelVersion()
    {
        try
        {
            if (File.Exists("/proc/sys/kernel/osrelease"))
            {
                return File.ReadAllText("/proc/sys/kernel/osrelease").Trim();
            }

            return RuntimeInformation.OSDescription;
        }
        catch
        {
            return null;
        }
    }

    private static Dictionary<string, string> ParseOsRelease()
    {
        var data = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

        try
        {
            if (!File.Exists("/etc/os-release"))
            {
                return data;
            }

            foreach (var line in File.ReadAllLines("/etc/os-release"))
            {
                if (string.IsNullOrWhiteSpace(line) || line.StartsWith('#'))
                {
                    continue;
                }

                var delimiter = line.IndexOf('=');
                if (delimiter <= 0)
                {
                    continue;
                }

                var key = line[..delimiter].Trim();
                var value = line[(delimiter + 1)..].Trim().Trim('"');
                if (!string.IsNullOrWhiteSpace(key))
                {
                    data[key] = value;
                }
            }
        }
        catch
        {
            return data;
        }

        return data;
    }
}
