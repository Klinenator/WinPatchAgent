using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class MacShellScriptJobExecutor : IJobExecutor
{
    private readonly ILogger<MacShellScriptJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public MacShellScriptJobExecutor(
        ILogger<MacShellScriptJobExecutor> logger,
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
        if (!_options.EnableMacShellScriptExecution || state.CurrentJob is null || !OperatingSystem.IsMacOS())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsMacShellScriptJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedMacScriptJobAsync(state, job, cancellationToken),
            "Installing" => await FailStaleInstallingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedMacScriptJobAsync(
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

        _logger.LogInformation("Starting macOS shell script execution for job {JobId}", job.JobId);

        var executionResult = await RunScriptWorkflowAsync(job, cancellationToken);

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
                executionResult.ErrorCode ?? "MAC_SCRIPT_FAILED",
                executionResult.ErrorMessage ?? "macOS shell script execution failed.",
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
            "MAC_SCRIPT_RESUME_UNSUPPORTED",
            "Agent restarted while macOS shell script execution was in progress; execution cannot be resumed safely.");

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
            "Reported macOS shell script completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        // Force an inventory refresh next loop so package/update counts are
        // refreshed quickly after script-driven package changes.
        state.LastInventoryAtUtc = null;
        state.CurrentJob = null;
        return true;
    }

    private async Task<MacScriptExecutionResult> RunScriptWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var scriptContent = BuildScriptContent(job);
        if (string.IsNullOrWhiteSpace(scriptContent))
        {
            return MacScriptExecutionResult.Fail(
                "MAC_SCRIPT_NO_WORK",
                "No script content provided. Set macos_script.script or macos_script.script_url.");
        }

        var tempScriptPath = Path.Combine(
            Path.GetTempPath(),
            "patchagent-macos-script-" + Guid.NewGuid().ToString("N") + ".sh");

        try
        {
            await File.WriteAllTextAsync(tempScriptPath, scriptContent, new UTF8Encoding(false), cancellationToken);

            var result = await RunProcessAsync(
                "/bin/bash",
                [tempScriptPath],
                TimeSpan.FromSeconds(Math.Max(30, _options.MacShellScriptCommandTimeoutSeconds)),
                cancellationToken);

            if (result.TimedOut)
            {
                return MacScriptExecutionResult.Fail(
                    "MAC_SCRIPT_TIMEOUT",
                    "macOS shell script timed out.");
            }

            if (result.ExitCode != 0)
            {
                return MacScriptExecutionResult.Fail(
                    "MAC_SCRIPT_COMMAND_FAILED",
                    BuildErrorSummary(result.StandardError, result.StandardOutput));
            }

            var rebootRequired = DetectRebootRequirement(result.StandardOutput, result.StandardError);
            return MacScriptExecutionResult.Ok(rebootRequired);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            return MacScriptExecutionResult.Fail("MAC_SCRIPT_EXCEPTION", ex.Message);
        }
        finally
        {
            TryDeleteFile(tempScriptPath);
        }
    }

    private static string BuildScriptContent(JobExecutionState job)
    {
        if (!string.IsNullOrWhiteSpace(job.MacShellScript))
        {
            return job.MacShellScript;
        }

        if (!string.IsNullOrWhiteSpace(job.MacShellScriptUrl))
        {
            if (!Uri.TryCreate(job.MacShellScriptUrl, UriKind.Absolute, out var uri)
                || !(uri.Scheme == Uri.UriSchemeHttps || uri.Scheme == Uri.UriSchemeHttp))
            {
                return string.Empty;
            }

            var escapedUrl = EscapeShellSingleQuoted(uri.ToString());
            return
                "set -euo pipefail\n" +
                $"script_url='{escapedUrl}'\n" +
                "temp_script=\"$(mktemp \"${TMPDIR:-/tmp}/patchagent-script-XXXXXX.sh\")\"\n" +
                "cleanup() { rm -f \"$temp_script\"; }\n" +
                "trap cleanup EXIT\n" +
                "curl -fsSL \"$script_url\" -o \"$temp_script\"\n" +
                "/bin/bash \"$temp_script\"\n";
        }

        return string.Empty;
    }

    private static string EscapeShellSingleQuoted(string value)
    {
        return value.Replace("'", "'\"'\"'", StringComparison.Ordinal);
    }

    private static bool DetectRebootRequirement(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("restart", StringComparison.Ordinal)
            || combined.Contains("reboot", StringComparison.Ordinal);
    }

    private static bool IsMacShellScriptJob(JobExecutionState job)
    {
        if (!string.IsNullOrWhiteSpace(job.MacShellScript)
            || !string.IsNullOrWhiteSpace(job.MacShellScriptUrl))
        {
            return true;
        }

        return string.Equals(job.JobType, "macos_shell_script", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "mac_shell_script", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "macos_run_script", StringComparison.OrdinalIgnoreCase);
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "Shell script failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(6);

        return string.Join(" | ", lines);
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

    private static void TryDeleteFile(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
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

    private readonly record struct MacScriptExecutionResult(
        bool Success,
        bool RebootRequired,
        string? ErrorCode,
        string? ErrorMessage)
    {
        public static MacScriptExecutionResult Ok(bool rebootRequired)
        {
            return new MacScriptExecutionResult(true, rebootRequired, null, null);
        }

        public static MacScriptExecutionResult Fail(
            string code,
            string message,
            bool rebootRequired = false)
        {
            return new MacScriptExecutionResult(false, rebootRequired, code, message);
        }
    }
}
