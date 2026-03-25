using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class LinuxAptJobExecutor : IJobExecutor
{
    private readonly ILogger<LinuxAptJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public LinuxAptJobExecutor(
        ILogger<LinuxAptJobExecutor> logger,
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
        if (!_options.EnableAptJobExecution || state.CurrentJob is null || !OperatingSystem.IsLinux())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsAptJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedAptJobAsync(state, job, cancellationToken),
            "Installing" => await FailStaleInstallingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedAptJobAsync(
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

        _logger.LogInformation("Starting apt execution for job {JobId}", job.JobId);

        var executionResult = await RunAptWorkflowAsync(job, cancellationToken);

        job.PercentComplete = 100;
        job.StateChangedAtUtc = DateTimeOffset.UtcNow;
        job.State = executionResult.Success ? "Succeeded" : "Failed";

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
                executionResult.ErrorCode ?? "APT_COMMAND_FAILED",
                executionResult.ErrorMessage ?? "apt execution failed");

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
            "APT_RESUME_UNSUPPORTED",
            "Agent restarted while apt installation was in progress; execution cannot be resumed safely.");

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
            "Reported apt completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        // Force an inventory refresh on the next loop so available package counts
        // reflect the latest apt install/upgrade result without waiting for
        // the full inventory interval.
        state.LastInventoryAtUtc = null;
        state.CurrentJob = null;
        return true;
    }

    private async Task<AptExecutionResult> RunAptWorkflowAsync(JobExecutionState job, CancellationToken cancellationToken)
    {
        if (!File.Exists("/usr/bin/apt-get") && !File.Exists("/usr/bin/apt"))
        {
            return AptExecutionResult.Fail("APT_NOT_FOUND", "apt-get was not found on this Linux host.");
        }

        if (_options.AptRunUpdateBeforeInstall)
        {
            var updateResult = await RunAptCommandAsync(["update"], cancellationToken);
            if (!updateResult.Success)
            {
                return updateResult;
            }
        }

        if (job.AptUpgradeAll)
        {
            var upgradeResult = await RunAptCommandAsync(["-y", "upgrade"], cancellationToken);
            if (!upgradeResult.Success)
            {
                return upgradeResult;
            }
        }

        if (job.AptPackages.Count > 0)
        {
            var installArgs = new List<string> { "install", "-y" };
            installArgs.AddRange(job.AptPackages);

            var installResult = await RunAptCommandAsync(installArgs, cancellationToken);
            if (!installResult.Success)
            {
                return installResult;
            }
        }

        if (!job.AptUpgradeAll && job.AptPackages.Count == 0)
        {
            return AptExecutionResult.Fail(
                "APT_NO_WORK",
                "No apt work specified. Set apt.upgrade_all=true or provide apt.packages[].");
        }

        job.SimulatedRebootRequired = File.Exists("/var/run/reboot-required");
        return AptExecutionResult.Ok();
    }

    private async Task<AptExecutionResult> RunAptCommandAsync(
        IReadOnlyList<string> aptArgs,
        CancellationToken cancellationToken)
    {
        var useSudo = _options.AptUseSudoWhenNotRoot && !IsRootUser();
        var executable = useSudo ? "sudo" : "apt-get";
        var arguments = useSudo
            ? new[] { "-n", "apt-get" }.Concat(aptArgs).ToList()
            : aptArgs.ToList();

        var result = await RunProcessAsync(
            executable,
            arguments,
            TimeSpan.FromSeconds(Math.Max(30, _options.AptCommandTimeoutSeconds)),
            cancellationToken);

        if (result.ExitCode == 0)
        {
            return AptExecutionResult.Ok();
        }

        var summary = BuildErrorSummary(result.StandardError, result.StandardOutput);
        var errorCode = result.TimedOut ? "APT_TIMEOUT" : "APT_COMMAND_FAILED";
        return AptExecutionResult.Fail(errorCode, summary);
    }

    private static bool IsRootUser()
    {
        return string.Equals(Environment.UserName, "root", StringComparison.OrdinalIgnoreCase);
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "apt command failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(5);

        return string.Join(" | ", lines);
    }

    private async Task<ProcessResult> RunProcessAsync(
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

        startInfo.Environment["DEBIAN_FRONTEND"] = "noninteractive";

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

    private static bool IsAptJob(JobExecutionState job)
    {
        if (job.AptUpgradeAll || job.AptPackages.Count > 0)
        {
            return true;
        }

        return string.Equals(job.JobType, "ubuntu_apt_upgrade", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "linux_apt_upgrade", StringComparison.OrdinalIgnoreCase);
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

    private static JobCompletionReport BuildFailureReport(string code, string message)
    {
        return new JobCompletionReport
        {
            FinalState = "Failed",
            InstallResult = "failed",
            RebootRequired = false,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            ErrorCode = code,
            ErrorMessage = message,
            Retryable = true
        };
    }

    private readonly record struct ProcessResult(
        int ExitCode,
        string StandardOutput,
        string StandardError,
        bool TimedOut);

    private readonly record struct AptExecutionResult(
        bool Success,
        string? ErrorCode,
        string? ErrorMessage)
    {
        public static AptExecutionResult Ok()
        {
            return new AptExecutionResult(true, null, null);
        }

        public static AptExecutionResult Fail(string code, string message)
        {
            return new AptExecutionResult(false, code, message);
        }
    }
}
