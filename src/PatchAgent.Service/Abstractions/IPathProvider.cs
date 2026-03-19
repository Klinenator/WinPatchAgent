namespace PatchAgent.Service.Abstractions;

public interface IPathProvider
{
    string RootPath { get; }

    string ConfigDirectory { get; }

    string CacheDirectory { get; }

    string LogsDirectory { get; }

    string QueueDirectory { get; }

    string StateDirectory { get; }

    string StateFilePath { get; }

    string TelemetryQueueFilePath { get; }

    Task EnsureCreatedAsync(CancellationToken cancellationToken);
}
