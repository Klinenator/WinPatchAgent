using System.Text.Json;
using Microsoft.Extensions.Logging;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class JsonFileStateStore : ILocalStateStore
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true
    };

    private readonly ILogger<JsonFileStateStore> _logger;
    private readonly IPathProvider _pathProvider;

    public JsonFileStateStore(
        ILogger<JsonFileStateStore> logger,
        IPathProvider pathProvider)
    {
        _logger = logger;
        _pathProvider = pathProvider;
    }

    public async Task<AgentState> LoadAsync(CancellationToken cancellationToken)
    {
        if (!File.Exists(_pathProvider.StateFilePath))
        {
            return new AgentState();
        }

        await using var stream = File.OpenRead(_pathProvider.StateFilePath);
        var state = await JsonSerializer.DeserializeAsync<AgentState>(
            stream,
            JsonOptions,
            cancellationToken);

        return state ?? new AgentState();
    }

    public async Task SaveAsync(AgentState state, CancellationToken cancellationToken)
    {
        await using var stream = File.Create(_pathProvider.StateFilePath);
        await JsonSerializer.SerializeAsync(stream, state, JsonOptions, cancellationToken);

        _logger.LogDebug("Persisted agent state for device {DeviceId}", state.DeviceId);
    }
}
