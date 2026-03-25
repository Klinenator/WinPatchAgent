using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class MacSoftwareUpdateJobExecutor : IJobExecutor
{
    private readonly ILogger<MacSoftwareUpdateJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public MacSoftwareUpdateJobExecutor(
        ILogger<MacSoftwareUpdateJobExecutor> logger,
        IOptions<AgentOptions> options,
        IPolicyClient policyClient,
        ITelemetryQueue telemetryQueue)
    {
        _logger = logger;
        _options = options.Value;
        _policyClient = policyClient;
        _telemetryQueue = telemetryQueue;
    }

    public async Task<bool> TryAdvanceAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (!_options.EnableMacSoftwareUpdateJobExecution || state.CurrentJob is null || !OperatingSystem.IsMacOS())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsMacSoftwareUpdateJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedMacSoftwareUpdateJobAsync(state, job, cancellationToken),
            "Installing" => await FailStaleInstallingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedMacSoftwareUpdateJobAsync(
        AgentState state,
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var now = DateTimeOffset.UtcNow;
        job.State = "Installing";
        job.StateChangedAtUtc = now;
        job.ExecutionStartedAtUtc = now;
        job.PercentComplete = 10;

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "install_started",
                new
                {
                    state.DeviceId,
                    job.JobId,
                    job.JobType,
                    job.CorrelationId
                }),
            cancellationToken);

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "job_state_changed",
                new
                {
                    state.DeviceId,
                    job.JobId,
                    State = job.State
                }),
            cancellationToken);

        _logger.LogInformation("Starting macOS software update execution for job {JobId}", job.JobId);

        var executionResult = await RunMacSoftwareUpdateWorkflowAsync(job, cancellationToken);

        job.PercentComplete = 100;
        job.StateChangedAtUtc = DateTimeOffset.UtcNow;
        job.State = executionResult.Success ? "Succeeded" : "Failed";
        job.SimulatedRebootRequired = executionResult.RebootRequired;

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "install_completed",
                new
                {
                    state.DeviceId,
                    job.JobId,
                    FinalState = job.State
                }),
            cancellationToken);

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "job_state_changed",
                new
                {
                    state.DeviceId,
                    job.JobId,
                    State = job.State
                }),
            cancellationToken);

        var completionReport = executionResult.Success
            ? BuildCompletionReport(job)
            : BuildFailureReport(
                executionResult.ErrorCode ?? "MACOS_UPDATE_FAILED",
                executionResult.ErrorMessage ?? "macOS softwareupdate execution failed.",
                executionResult.RebootRequired);

        return await ReportAndClearAsync(state, job, completionReport, cancellationToken);
    }

    private async Task<bool> FailStaleInstallingJobAsync(
        AgentState state,
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        job.State = "Failed";
        job.StateChangedAtUtc = DateTimeOffset.UtcNow;

        var report = BuildFailureReport(
            "MACOS_UPDATE_RESUME_UNSUPPORTED",
            "Agent restarted while macOS softwareupdate installation was in progress; execution cannot be resumed safely.");

        return await ReportAndClearAsync(state, job, report, cancellationToken);
    }

    private async Task<bool> ReportAndClearAsync(
        AgentState state,
        JobExecutionState job,
        JobCompletionReport report,
        CancellationToken cancellationToken)
    {
        await _policyClient.CompleteJobAsync(state, job, report, cancellationToken);

        _logger.LogInformation(
            "Reported macOS software update completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private async Task<MacSoftwareUpdateExecutionResult> RunMacSoftwareUpdateWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        if (!File.Exists("/usr/sbin/softwareupdate") && !File.Exists("/usr/bin/softwareupdate"))
        {
            return MacSoftwareUpdateExecutionResult.Fail(
                "MACOS_SOFTWAREUPDATE_NOT_FOUND",
                "softwareupdate was not found on this macOS host.");
        }

        if (!job.MacOsInstallAll && job.MacOsUpdateLabels.Count == 0)
        {
            return MacSoftwareUpdateExecutionResult.Fail(
                "MACOS_UPDATE_NO_WORK",
                "No macOS update work specified. Set macos_update.install_all=true or provide macos_update.labels[].");
        }

        var rebootRequired = false;

        if (job.MacOsInstallAll)
        {
            var installAllResult = await RunSoftwareUpdateCommandAsync(
                ["--install", "--all"],
                cancellationToken);

            if (!installAllResult.Success)
            {
                return MacSoftwareUpdateExecutionResult.Fail(
                    installAllResult.ErrorCode ?? "MACOS_SOFTWAREUPDATE_COMMAND_FAILED",
                    installAllResult.ErrorMessage ?? "softwareupdate --install --all failed.");
            }

            rebootRequired = rebootRequired || installAllResult.RebootRequired;
            return MacSoftwareUpdateExecutionResult.Ok(rebootRequired);
        }

        foreach (var label in job.MacOsUpdateLabels)
        {
            var installLabelResult = await RunSoftwareUpdateCommandAsync(
                ["--install", label],
                cancellationToken);

            if (!installLabelResult.Success)
            {
                return MacSoftwareUpdateExecutionResult.Fail(
                    installLabelResult.ErrorCode ?? "MACOS_SOFTWAREUPDATE_COMMAND_FAILED",
                    installLabelResult.ErrorMessage ?? $"softwareupdate --install {label} failed.");
            }

            rebootRequired = rebootRequired || installLabelResult.RebootRequired;
        }

        return MacSoftwareUpdateExecutionResult.Ok(rebootRequired);
    }

    private async Task<MacSoftwareUpdateExecutionResult> RunSoftwareUpdateCommandAsync(
        IReadOnlyList<string> args,
        CancellationToken cancellationToken)
    {
        var result = await RunProcessAsync(
            "softwareupdate",
            args,
            TimeSpan.FromSeconds(Math.Max(120, _options.MacSoftwareUpdateCommandTimeoutSeconds)),
            cancellationToken);

        if (result.TimedOut)
        {
            return MacSoftwareUpdateExecutionResult.Fail(
                "MACOS_SOFTWAREUPDATE_TIMEOUT",
                "macOS softwareupdate command timed out.");
        }

        if (result.ExitCode != 0)
        {
            return MacSoftwareUpdateExecutionResult.Fail(
                "MACOS_SOFTWAREUPDATE_COMMAND_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput));
        }

        var rebootRequired = DetectRebootRequirement(result.StandardOutput, result.StandardError);
        return MacSoftwareUpdateExecutionResult.Ok(rebootRequired);
    }

    private static bool DetectRebootRequirement(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("restart", StringComparison.Ordinal)
            || combined.Contains("reboot", StringComparison.Ordinal);
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "softwareupdate command failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(6);

        return string.Join(" | ", lines);
    }

    private static bool IsMacSoftwareUpdateJob(JobExecutionState job)
    {
        if (job.MacOsInstallAll || job.MacOsUpdateLabels.Count > 0)
        {
            return true;
        }

        return string.Equals(job.JobType, "macos_software_update", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "macos_update_install", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "mac_software_update", StringComparison.OrdinalIgnoreCase);
    }

    private static JobCompletionReport BuildCompletionReport(JobExecutionState job)
    {
        return new JobCompletionReport
        {
            FinalState = "Succeeded",
            InstallResult = "success",
            RebootRequired = job.SimulatedRebootRequired,
            RebootPerformed = false,
            PostRebootValidation = "not_run"
        };
    }

    private static JobCompletionReport BuildFailureReport(
        string code,
        string message,
        bool rebootRequired = false)
    {
        return new JobCompletionReport
        {
            FinalState = "Failed",
            InstallResult = "failed",
            RebootRequired = rebootRequired,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            ErrorCode = code,
            ErrorMessage = message,
            Retryable = true
        };
    }

    private static async Task<ProcessResult> RunProcessAsync(
        string executable,
        IReadOnlyList<string> args,
        TimeSpan timeout,
        CancellationToken cancellationToken)
    {
        var startInfo = new ProcessStartInfo
        {
            FileName = executable,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false
        };

        foreach (var arg in args)
        {
            startInfo.ArgumentList.Add(arg);
        }

        using var process = new Process { StartInfo = startInfo };
        var stdout = new StringBuilder();
        var stderr = new StringBuilder();

        process.OutputDataReceived += (_, eventArgs) =>
        {
            if (eventArgs.Data is not null)
            {
                stdout.AppendLine(eventArgs.Data);
            }
        };
        process.ErrorDataReceived += (_, eventArgs) =>
        {
            if (eventArgs.Data is not null)
            {
                stderr.AppendLine(eventArgs.Data);
            }
        };

        if (!process.Start())
        {
            return new ProcessResult(-1, stdout.ToString(), stderr.ToString(), TimedOut: false);
        }

        process.BeginOutputReadLine();
        process.BeginErrorReadLine();

        using var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeoutCts.CancelAfter(timeout);

        try
        {
            await process.WaitForExitAsync(timeoutCts.Token);
            return new ProcessResult(process.ExitCode, stdout.ToString(), stderr.ToString(), TimedOut: false);
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            TryKill(process);
            return new ProcessResult(-1, stdout.ToString(), stderr.ToString(), TimedOut: true);
        }
    }

    private static void TryKill(Process process)
    {
        try
        {
            if (!process.HasExited)
            {
                process.Kill(entireProcessTree: true);
            }
        }
        catch
        {
        }
    }

    private readonly record struct ProcessResult(
        int ExitCode,
        string StandardOutput,
        string StandardError,
        bool TimedOut);

    private readonly record struct MacSoftwareUpdateExecutionResult(
        bool Success,
        bool RebootRequired,
        string? ErrorCode,
        string? ErrorMessage)
    {
        public static MacSoftwareUpdateExecutionResult Ok(bool rebootRequired)
        {
            return new MacSoftwareUpdateExecutionResult(true, rebootRequired, null, null);
        }

        public static MacSoftwareUpdateExecutionResult Fail(
            string code,
            string message,
            bool rebootRequired = false)
        {
            return new MacSoftwareUpdateExecutionResult(false, rebootRequired, code, message);
        }
    }
}
