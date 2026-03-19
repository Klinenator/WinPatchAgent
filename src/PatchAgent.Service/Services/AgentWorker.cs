using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Configuration;

namespace PatchAgent.Service.Services;

public sealed class AgentWorker : BackgroundService
{
    private readonly ILogger<AgentWorker> _logger;
    private readonly AgentCoordinator _coordinator;
    private readonly AgentOptions _options;

    public AgentWorker(
        ILogger<AgentWorker> logger,
        AgentCoordinator coordinator,
        IOptions<AgentOptions> options)
    {
        _logger = logger;
        _coordinator = coordinator;
        _options = options.Value;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation("Starting {ServiceName}", _options.ServiceName);

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await _coordinator.RunOnceAsync(stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                _logger.LogError(ex, "Agent loop failed");
            }

            try
            {
                await Task.Delay(TimeSpan.FromSeconds(_options.LoopDelaySeconds), stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
        }

        _logger.LogInformation("Stopping {ServiceName}", _options.ServiceName);
    }
}
