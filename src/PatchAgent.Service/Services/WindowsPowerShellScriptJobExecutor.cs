using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.Win32;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class WindowsPowerShellScriptJobExecutor : IJobExecutor
{
    private readonly ILogger<WindowsPowerShellScriptJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public WindowsPowerShellScriptJobExecutor(
        ILogger<WindowsPowerShellScriptJobExecutor> logger,
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
        if (!_options.EnableWindowsPowerShellScriptExecution || state.CurrentJob is null || !OperatingSystem.IsWindows())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsWindowsScriptJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedScriptJobAsync(state, job, cancellationToken),
            "Installing" => await FailStaleInstallingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedScriptJobAsync(
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

        _logger.LogInformation("Starting PowerShell script execution for job {JobId}", job.JobId);

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
                executionResult.ErrorCode ?? "WINDOWS_SCRIPT_FAILED",
                executionResult.ErrorMessage ?? "PowerShell script execution failed.",
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
            "WINDOWS_SCRIPT_RESUME_UNSUPPORTED",
            "Agent restarted while PowerShell script execution was in progress; execution cannot be resumed safely.");

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
            "Reported PowerShell script completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private async Task<WindowsScriptExecutionResult> RunScriptWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var scriptContent = BuildScriptContent(job);
        if (string.IsNullOrWhiteSpace(scriptContent))
        {
            return WindowsScriptExecutionResult.Fail(
                "WINDOWS_SCRIPT_NO_WORK",
                "No script content provided. Set windows_script.script or windows_script.script_url.");
        }

        var encoded = Convert.ToBase64String(Encoding.Unicode.GetBytes(scriptContent));
        var result = await RunProcessAsync(
            "powershell.exe",
            [
                "-NoProfile",
                "-NonInteractive",
                "-ExecutionPolicy",
                "Bypass",
                "-EncodedCommand",
                encoded
            ],
            TimeSpan.FromSeconds(Math.Max(30, _options.WindowsPowerShellScriptCommandTimeoutSeconds)),
            cancellationToken);

        if (result.TimedOut)
        {
            return WindowsScriptExecutionResult.Fail(
                "WINDOWS_SCRIPT_TIMEOUT",
                "PowerShell script timed out.");
        }

        if (result.ExitCode != 0)
        {
            return WindowsScriptExecutionResult.Fail(
                "WINDOWS_SCRIPT_COMMAND_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput));
        }

        return WindowsScriptExecutionResult.Ok(IsWindowsRebootPending());
    }

    private static string BuildScriptContent(JobExecutionState job)
    {
        if (!string.IsNullOrWhiteSpace(job.WindowsPowerShellScript))
        {
            return job.WindowsPowerShellScript;
        }

        if (!string.IsNullOrWhiteSpace(job.WindowsPowerShellScriptUrl))
        {
            if (!Uri.TryCreate(job.WindowsPowerShellScriptUrl, UriKind.Absolute, out var uri)
                || !(uri.Scheme == Uri.UriSchemeHttps || uri.Scheme == Uri.UriSchemeHttp))
            {
                return string.Empty;
            }

            var escapedUrl = uri.ToString().Replace("'", "''", StringComparison.Ordinal);
            return
                "$ErrorActionPreference = 'Stop'\n" +
                "$ProgressPreference = 'SilentlyContinue'\n" +
                $"$scriptUrl = '{escapedUrl}'\n" +
                "$tempScript = Join-Path $env:TEMP ('patchagent-script-' + [guid]::NewGuid().ToString('N') + '.ps1')\n" +
                "try {\n" +
                "  Invoke-WebRequest -Uri $scriptUrl -UseBasicParsing -OutFile $tempScript\n" +
                "  & $tempScript\n" +
                "  if ($LASTEXITCODE -ne $null -and $LASTEXITCODE -ne 0) { exit $LASTEXITCODE }\n" +
                "} finally {\n" +
                "  Remove-Item -Path $tempScript -Force -ErrorAction SilentlyContinue\n" +
                "}\n";
        }

        return string.Empty;
    }

    private static bool IsWindowsScriptJob(JobExecutionState job)
    {
        if (!string.IsNullOrWhiteSpace(job.WindowsPowerShellScript)
            || !string.IsNullOrWhiteSpace(job.WindowsPowerShellScriptUrl))
        {
            return true;
        }

        return string.Equals(job.JobType, "windows_powershell_script", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "windows_script", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "windows_run_script", StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsWindowsRebootPending()
    {
        if (!OperatingSystem.IsWindows())
        {
            return false;
        }

        try
        {
            using var cbsKey = Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending");
            if (cbsKey is not null)
            {
                return true;
            }
        }
        catch
        {
        }

        try
        {
            using var wuKey = Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired");
            if (wuKey is not null)
            {
                return true;
            }
        }
        catch
        {
        }

        return false;
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "PowerShell script failed without output.";
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

    private readonly record struct ProcessResult(
        int ExitCode,
        string StandardOutput,
        string StandardError,
        bool TimedOut);

    private readonly record struct WindowsScriptExecutionResult(
        bool Success,
        bool RebootRequired,
        string? ErrorCode,
        string? ErrorMessage)
    {
        public static WindowsScriptExecutionResult Ok(bool rebootRequired)
        {
            return new WindowsScriptExecutionResult(true, rebootRequired, null, null);
        }

        public static WindowsScriptExecutionResult Fail(
            string code,
            string message,
            bool rebootRequired = false)
        {
            return new WindowsScriptExecutionResult(false, rebootRequired, code, message);
        }
    }
}
