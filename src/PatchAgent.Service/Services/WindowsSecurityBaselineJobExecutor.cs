using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class WindowsSecurityBaselineJobExecutor : IJobExecutor
{
    private const int MaxReportedOutputChars = 24000;

    private readonly ILogger<WindowsSecurityBaselineJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public WindowsSecurityBaselineJobExecutor(
        ILogger<WindowsSecurityBaselineJobExecutor> logger,
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
        if (!_options.EnableWindowsSecurityBaselineExecution || state.CurrentJob is null || !OperatingSystem.IsWindows())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsSecurityBaselineJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedJobAsync(state, job, cancellationToken),
            "Installing" => await FailStaleInstallingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedJobAsync(
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

        _logger.LogInformation("Starting Windows security baseline enforcement for job {JobId}", job.JobId);

        var executionResult = await RunBaselineWorkflowAsync(job, cancellationToken);

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
            ? BuildSuccessReport(executionResult)
            : BuildFailureReport(
                executionResult.ErrorCode ?? "SECURITY_BASELINE_FAILED",
                executionResult.ErrorMessage ?? "Windows security baseline enforcement failed.",
                executionResult.StandardOutput,
                executionResult.StandardError);

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
            "SECURITY_BASELINE_RESUME_UNSUPPORTED",
            "Agent restarted while security baseline enforcement was in progress; execution cannot be resumed safely.");

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
            "Reported security baseline completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private async Task<BaselineExecutionResult> RunBaselineWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var script = BuildBaselineScript(job);
        var encoded = Convert.ToBase64String(Encoding.Unicode.GetBytes(script));

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
            TimeSpan.FromSeconds(120),
            cancellationToken);

        if (result.TimedOut)
        {
            return BaselineExecutionResult.Fail(
                "SECURITY_BASELINE_TIMEOUT",
                "Security baseline enforcement script timed out.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        if (result.ExitCode != 0)
        {
            return BaselineExecutionResult.Fail(
                "SECURITY_BASELINE_COMMAND_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput),
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        return BaselineExecutionResult.Ok(result.StandardOutput, result.StandardError);
    }

    private static string BuildBaselineScript(JobExecutionState job)
    {
        var sb = new StringBuilder();
        sb.AppendLine("$ErrorActionPreference = 'Stop'");
        sb.AppendLine("$actions = @()");
        sb.AppendLine("$errors = @()");
        sb.AppendLine();

        // Disable built-in Administrator account (CC6.3)
        if (job.BaselineDisableBuiltinAdministrator)
        {
            sb.AppendLine("try {");
            sb.AppendLine("  $adminUser = Get-LocalUser -Name 'Administrator' -ErrorAction SilentlyContinue");
            sb.AppendLine("  if ($null -ne $adminUser -and $adminUser.Enabled) {");
            sb.AppendLine("    Disable-LocalUser -Name 'Administrator'");
            sb.AppendLine("    $actions += 'Disabled built-in Administrator account.'");
            sb.AppendLine("  } else {");
            sb.AppendLine("    $actions += 'Built-in Administrator account already disabled or not found.'");
            sb.AppendLine("  }");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to disable Administrator: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        // Disable Guest account (CC6.1)
        if (job.BaselineDisableGuestAccount)
        {
            sb.AppendLine("try {");
            sb.AppendLine("  $guestUser = Get-LocalUser -Name 'Guest' -ErrorAction SilentlyContinue");
            sb.AppendLine("  if ($null -ne $guestUser -and $guestUser.Enabled) {");
            sb.AppendLine("    Disable-LocalUser -Name 'Guest'");
            sb.AppendLine("    $actions += 'Disabled Guest account.'");
            sb.AppendLine("  } else {");
            sb.AppendLine("    $actions += 'Guest account already disabled or not found.'");
            sb.AppendLine("  }");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to disable Guest: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        // Account lockout policy (CC6.1)
        if (job.BaselineEnforceLockoutPolicy)
        {
            var threshold = job.BaselineLockoutThreshold > 0 ? job.BaselineLockoutThreshold : 5;
            var duration = job.BaselineLockoutDurationMinutes > 0 ? job.BaselineLockoutDurationMinutes : 15;
            sb.AppendLine("try {");
            sb.AppendLine($"  & net.exe accounts /lockoutthreshold:{threshold} /lockoutduration:{duration} /lockoutwindow:{duration} 2>&1 | Out-Null");
            sb.AppendLine($"  $actions += 'Set lockout policy: threshold={threshold}, duration={duration}min.'");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to set lockout policy: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        // Screen lock / idle timeout (CC6.1)
        if (job.BaselineEnforceScreenLock)
        {
            var timeoutSeconds = job.BaselineScreenLockTimeoutSeconds > 0 ? job.BaselineScreenLockTimeoutSeconds : 900;
            sb.AppendLine("try {");
            sb.AppendLine("  $regPath = 'HKLM:\\SOFTWARE\\Policies\\Microsoft\\Windows\\Control Panel\\Desktop'");
            sb.AppendLine("  if (-not (Test-Path $regPath)) { New-Item -Path $regPath -Force | Out-Null }");
            sb.AppendLine($"  Set-ItemProperty -Path $regPath -Name 'ScreenSaveTimeOut' -Value '{timeoutSeconds}' -Type String -Force");
            sb.AppendLine("  Set-ItemProperty -Path $regPath -Name 'ScreenSaveActive' -Value '1' -Type String -Force");
            sb.AppendLine("  Set-ItemProperty -Path $regPath -Name 'ScreenSaverIsSecure' -Value '1' -Type String -Force");
            sb.AppendLine($"  $actions += 'Set screen lock timeout to {timeoutSeconds} seconds.'");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to set screen lock: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        // UAC level (CC6.1) — enforce prompt_consent_secure_desktop (value 2)
        if (job.BaselineEnforceUac)
        {
            sb.AppendLine("try {");
            sb.AppendLine("  $uacPath = 'HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System'");
            sb.AppendLine("  Set-ItemProperty -Path $uacPath -Name 'EnableLUA' -Value 1 -Type DWord -Force");
            sb.AppendLine("  Set-ItemProperty -Path $uacPath -Name 'ConsentPromptBehaviorAdmin' -Value 2 -Type DWord -Force");
            sb.AppendLine("  $actions += 'Enforced UAC: EnableLUA=1, ConsentPromptBehaviorAdmin=2 (prompt on secure desktop).'");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to enforce UAC: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        // Windows Firewall — enable all profiles (CC6.1)
        if (job.BaselineEnableFirewall)
        {
            sb.AppendLine("try {");
            sb.AppendLine("  Set-NetFirewallProfile -Profile Domain,Public,Private -Enabled True -ErrorAction Stop");
            sb.AppendLine("  $actions += 'Enabled Windows Firewall on all profiles.'");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to enable firewall: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        // Windows Defender real-time protection (CC6.1)
        if (job.BaselineEnableDefender)
        {
            sb.AppendLine("try {");
            sb.AppendLine("  Set-MpPreference -DisableRealtimeMonitoring $false -ErrorAction Stop");
            sb.AppendLine("  $actions += 'Enabled Defender real-time protection.'");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to enable Defender real-time: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        // Audit policy (CC7.2) — logon events, privilege use, object access
        if (job.BaselineEnforceAuditPolicy)
        {
            sb.AppendLine("try {");
            sb.AppendLine("  & auditpol.exe /set /subcategory:'Logon' /success:enable /failure:enable 2>&1 | Out-Null");
            sb.AppendLine("  & auditpol.exe /set /subcategory:'Logoff' /success:enable /failure:enable 2>&1 | Out-Null");
            sb.AppendLine("  & auditpol.exe /set /subcategory:'Sensitive Privilege Use' /success:enable /failure:enable 2>&1 | Out-Null");
            sb.AppendLine("  & auditpol.exe /set /subcategory:'Non Sensitive Privilege Use' /success:enable /failure:enable 2>&1 | Out-Null");
            sb.AppendLine("  & auditpol.exe /set /subcategory:'File System' /success:enable /failure:enable 2>&1 | Out-Null");
            sb.AppendLine("  & auditpol.exe /set /subcategory:'Registry' /success:enable /failure:enable 2>&1 | Out-Null");
            sb.AppendLine("  $actions += 'Enforced audit policy: logon, privilege use, and object access.'");
            sb.AppendLine("} catch {");
            sb.AppendLine("  $errors += 'Failed to set audit policy: ' + $_.Exception.Message");
            sb.AppendLine("}");
            sb.AppendLine();
        }

        sb.AppendLine("if ($actions.Count -gt 0) { Write-Output ($actions -join \"`n\") }");
        sb.AppendLine("if ($errors.Count -gt 0) {");
        sb.AppendLine("  Write-Error ($errors -join \"`n\")");
        sb.AppendLine("  exit 1");
        sb.AppendLine("}");

        return sb.ToString();
    }

    private static bool IsSecurityBaselineJob(JobExecutionState job)
    {
        return string.Equals(job.JobType, "windows_security_baseline", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "security_baseline", StringComparison.OrdinalIgnoreCase);
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "Security baseline script failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(6);

        return string.Join(" | ", lines);
    }

    private static JobCompletionReport BuildCompletionReport(JobExecutionState job)
    {
        return BuildSuccessReport(BaselineExecutionResult.Ok(null, null));
    }

    private static JobCompletionReport BuildSuccessReport(BaselineExecutionResult result)
    {
        return new JobCompletionReport
        {
            FinalState = "Succeeded",
            InstallResult = "success",
            RebootRequired = false,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            Summary = "Windows security baseline enforced successfully.",
            Output = TruncateForReport(result.StandardOutput),
            ErrorOutput = TruncateForReport(result.StandardError)
        };
    }

    private static JobCompletionReport BuildFailureReport(
        string code,
        string message,
        string? standardOutput = null,
        string? standardError = null)
    {
        return new JobCompletionReport
        {
            FinalState = "Failed",
            InstallResult = "failed",
            RebootRequired = false,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            Summary = message,
            Output = TruncateForReport(standardOutput),
            ErrorOutput = TruncateForReport(standardError),
            ErrorCode = code,
            ErrorMessage = message,
            Retryable = true
        };
    }

    private static string? TruncateForReport(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return null;
        }

        var normalized = value.Replace("\r\n", "\n", StringComparison.Ordinal).Trim();
        if (normalized.Length <= MaxReportedOutputChars)
        {
            return normalized;
        }

        var remaining = normalized.Length - MaxReportedOutputChars;
        return normalized[..MaxReportedOutputChars] + $"\n... [truncated {remaining} chars]";
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

    private readonly record struct BaselineExecutionResult(
        bool Success,
        string? ErrorCode,
        string? ErrorMessage,
        string? StandardOutput,
        string? StandardError)
    {
        public static BaselineExecutionResult Ok(string? standardOutput, string? standardError)
        {
            return new BaselineExecutionResult(true, null, null, standardOutput, standardError);
        }

        public static BaselineExecutionResult Fail(
            string code,
            string message,
            string? standardOutput = null,
            string? standardError = null)
        {
            return new BaselineExecutionResult(false, code, message, standardOutput, standardError);
        }
    }
}
