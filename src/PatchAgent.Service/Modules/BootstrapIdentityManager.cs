using Microsoft.Extensions.Logging;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Modules;

public sealed class BootstrapIdentityManager : IAgentIdentityManager
{
    private readonly ILogger<BootstrapIdentityManager> _logger;
    private readonly IPolicyClient _policyClient;

    public BootstrapIdentityManager(
        ILogger<BootstrapIdentityManager> logger,
        IPolicyClient policyClient)
    {
        _logger = logger;
        _policyClient = policyClient;
    }

    public async Task EnsureRegisteredAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (state.IsRegistered)
        {
            return;
        }

        await _policyClient.RegisterAsync(state, cancellationToken);

        _logger.LogInformation(
            "Registration completed for device {DeviceId} as {ServerAssignedAgentId}",
            state.DeviceId,
            state.ServerAssignedAgentId);
    }
}
