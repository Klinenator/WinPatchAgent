using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class WindowsEnsureLocalUserJobExecutor : IJobExecutor
{
    private const int MaxReportedOutputChars = 24000;

    private readonly ILogger<WindowsEnsureLocalUserJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public WindowsEnsureLocalUserJobExecutor(
        ILogger<WindowsEnsureLocalUserJobExecutor> logger,
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
        if (!_options.EnableWindowsEnsureLocalUserExecution || state.CurrentJob is null || !OperatingSystem.IsWindows())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsEnsureLocalUserJob(job))
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

        var username = string.IsNullOrWhiteSpace(job.EnsureLocalUserUsername)
            ? "local.admin"
            : job.EnsureLocalUserUsername;

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

        _logger.LogInformation(
            "Starting ensure-local-user execution for job {JobId}, username '{Username}'",
            job.JobId,
            username);

        var executionResult = await RunEnsureUserWorkflowAsync(job, username, cancellationToken);

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
                executionResult.ErrorCode ?? "ENSURE_LOCAL_USER_FAILED",
                executionResult.ErrorMessage ?? "Failed to ensure local user account.",
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
            "ENSURE_LOCAL_USER_RESUME_UNSUPPORTED",
            "Agent restarted while ensure-local-user execution was in progress; execution cannot be resumed safely.");

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
            "Reported ensure-local-user completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private async Task<EnsureUserExecutionResult> RunEnsureUserWorkflowAsync(
        JobExecutionState job,
        string username,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(job.EnsureLocalUserPassword))
        {
            return EnsureUserExecutionResult.Fail(
                "ENSURE_LOCAL_USER_NO_PASSWORD",
                "No password provided. Set ensure_local_user.password in the job payload.");
        }

        var script = BuildEnsureUserScript(username, job.EnsureLocalUserPassword, job.EnsureLocalUserAddToAdministrators);
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
            TimeSpan.FromSeconds(60),
            cancellationToken);

        if (result.TimedOut)
        {
            return EnsureUserExecutionResult.Fail(
                "ENSURE_LOCAL_USER_TIMEOUT",
                "Ensure-local-user script timed out.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        if (result.ExitCode != 0)
        {
            return EnsureUserExecutionResult.Fail(
                "ENSURE_LOCAL_USER_COMMAND_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput),
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        return EnsureUserExecutionResult.Ok(result.StandardOutput, result.StandardError);
    }

    private static string BuildEnsureUserScript(string username, string password, bool addToAdministrators)
    {
        var escapedUsername = username.Replace("'", "''", StringComparison.Ordinal);
        var escapedPassword = password.Replace("'", "''", StringComparison.Ordinal);

        var sb = new StringBuilder();
        sb.AppendLine("$ErrorActionPreference = 'Stop'");
        sb.AppendLine($"$username = '{escapedUsername}'");
        sb.AppendLine($"$password = ConvertTo-SecureString '{escapedPassword}' -AsPlainText -Force");
        sb.AppendLine();
        sb.AppendLine("$existingUser = Get-LocalUser -Name $username -ErrorAction SilentlyContinue");
        sb.AppendLine("if ($existingUser) {");
        sb.AppendLine("    Write-Output \"User '$username' already exists. No action taken.\"");
        sb.AppendLine("    exit 0");
        sb.AppendLine("}");
        sb.AppendLine();
        sb.AppendLine("New-LocalUser -Name $username -Password $password -PasswordNeverExpires $true -UserMayNotChangePassword $true -Description 'Local admin account managed by PatchAgent'");
        sb.AppendLine("Write-Output \"User '$username' created.\"");

        if (addToAdministrators)
        {
            sb.AppendLine();
            sb.AppendLine("Add-LocalGroupMember -Group 'Administrators' -Member $username");
            sb.AppendLine("Write-Output \"User '$username' added to Administrators group.\"");
        }

        return sb.ToString();
    }

    private static bool IsEnsureLocalUserJob(JobExecutionState job)
    {
        return string.Equals(job.JobType, "windows_ensure_local_user", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "ensure_local_user", StringComparison.OrdinalIgnoreCase);
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "Ensure-local-user script failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(6);

        return string.Join(" | ", lines);
    }

    private static JobCompletionReport BuildCompletionReport(JobExecutionState job)
    {
        return BuildSuccessReport(EnsureUserExecutionResult.Ok(null, null));
    }

    private static JobCompletionReport BuildSuccessReport(EnsureUserExecutionResult result)
    {
        return new JobCompletionReport
        {
            FinalState = "Succeeded",
            InstallResult = "success",
            RebootRequired = false,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            Summary = "Local user account ensured successfully.",
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

    private readonly record struct EnsureUserExecutionResult(
        bool Success,
        string? ErrorCode,
        string? ErrorMessage,
        string? StandardOutput,
        string? StandardError)
    {
        public static EnsureUserExecutionResult Ok(string? standardOutput, string? standardError)
        {
            return new EnsureUserExecutionResult(true, null, null, standardOutput, standardError);
        }

        public static EnsureUserExecutionResult Fail(
            string code,
            string message,
            string? standardOutput = null,
            string? standardError = null)
        {
            return new EnsureUserExecutionResult(false, code, message, standardOutput, standardError);
        }
    }
}
