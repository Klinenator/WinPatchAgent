using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class DispatchingJobExecutor : IJobExecutor
{
    private readonly LinuxAptJobExecutor _aptJobExecutor;
    private readonly WindowsUpdateJobExecutor _windowsUpdateJobExecutor;
    private readonly StubJobExecutor _stubJobExecutor;

    public DispatchingJobExecutor(
        LinuxAptJobExecutor aptJobExecutor,
        WindowsUpdateJobExecutor windowsUpdateJobExecutor,
        StubJobExecutor stubJobExecutor)
    {
        _aptJobExecutor = aptJobExecutor;
        _windowsUpdateJobExecutor = windowsUpdateJobExecutor;
        _stubJobExecutor = stubJobExecutor;
    }

    public async Task<bool> TryAdvanceAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (await _aptJobExecutor.TryAdvanceAsync(state, cancellationToken))
        {
            return true;
        }

        if (await _windowsUpdateJobExecutor.TryAdvanceAsync(state, cancellationToken))
        {
            return true;
        }

        return await _stubJobExecutor.TryAdvanceAsync(state, cancellationToken);
    }
}
