using System.Text.Json;
using Microsoft.Extensions.Logging;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class FileTelemetryQueue : ITelemetryQueue
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true
    };

    private readonly ILogger<FileTelemetryQueue> _logger;
    private readonly IPathProvider _pathProvider;

    public FileTelemetryQueue(
        ILogger<FileTelemetryQueue> logger,
        IPathProvider pathProvider)
    {
        _logger = logger;
        _pathProvider = pathProvider;
    }

    public async Task EnqueueAsync(TelemetryEvent telemetryEvent, CancellationToken cancellationToken)
    {
        var pending = await ReadPendingInternalAsync(cancellationToken);
        pending.Add(telemetryEvent);

        await using var stream = File.Create(_pathProvider.TelemetryQueueFilePath);
        await JsonSerializer.SerializeAsync(stream, pending, JsonOptions, cancellationToken);

        _logger.LogDebug("Queued telemetry event {EventType}", telemetryEvent.EventType);
    }

    public async Task<IReadOnlyList<TelemetryEvent>> ReadPendingAsync(CancellationToken cancellationToken)
    {
        return await ReadPendingInternalAsync(cancellationToken);
    }

    public Task ClearAsync(CancellationToken cancellationToken)
    {
        if (File.Exists(_pathProvider.TelemetryQueueFilePath))
        {
            File.Delete(_pathProvider.TelemetryQueueFilePath);
        }

        return Task.CompletedTask;
    }

    private async Task<List<TelemetryEvent>> ReadPendingInternalAsync(CancellationToken cancellationToken)
    {
        var path = _pathProvider.TelemetryQueueFilePath;
        if (!File.Exists(path))
        {
            return [];
        }

        await using var stream = File.OpenRead(path);
        var events = await JsonSerializer.DeserializeAsync<List<TelemetryEvent>>(
            stream,
            JsonOptions,
            cancellationToken);

        return events ?? [];
    }
}
