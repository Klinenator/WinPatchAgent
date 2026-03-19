using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;

namespace PatchAgent.Service.Services;

public sealed class SystemPathProvider : IPathProvider
{
    public SystemPathProvider(IOptions<AgentOptions> options)
    {
        RootPath = ResolveRootPath(options.Value.StorageRoot);
        ConfigDirectory = Path.Combine(RootPath, "config");
        CacheDirectory = Path.Combine(RootPath, "cache");
        LogsDirectory = Path.Combine(RootPath, "logs");
        QueueDirectory = Path.Combine(RootPath, "queue");
        StateDirectory = Path.Combine(RootPath, "state");
        StateFilePath = Path.Combine(StateDirectory, "agent-state.json");
        TelemetryQueueFilePath = Path.Combine(QueueDirectory, "telemetry-queue.json");
    }

    public string RootPath { get; }

    public string ConfigDirectory { get; }

    public string CacheDirectory { get; }

    public string LogsDirectory { get; }

    public string QueueDirectory { get; }

    public string StateDirectory { get; }

    public string StateFilePath { get; }

    public string TelemetryQueueFilePath { get; }

    public Task EnsureCreatedAsync(CancellationToken cancellationToken)
    {
        Directory.CreateDirectory(RootPath);
        Directory.CreateDirectory(ConfigDirectory);
        Directory.CreateDirectory(CacheDirectory);
        Directory.CreateDirectory(LogsDirectory);
        Directory.CreateDirectory(QueueDirectory);
        Directory.CreateDirectory(StateDirectory);

        return Task.CompletedTask;
    }

    private static string ResolveRootPath(string configuredPath)
    {
        if (!string.IsNullOrWhiteSpace(configuredPath))
        {
            return configuredPath;
        }

        if (OperatingSystem.IsWindows())
        {
            var programData = Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData);
            return Path.Combine(programData, "PatchAgent");
        }

        var home = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
        return Path.Combine(home, ".patchagent");
    }
}
