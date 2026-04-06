using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class WindowsRotateLocalUserPasswordJobExecutor : IJobExecutor
{
    private const int MinPasswordLength = 12;
    private const int MaxPasswordLength = 128;
    private const int DefaultPasswordLength = 20;
    private const int MaxReportedOutputChars = 24000;

    private static readonly char[] UppercaseChars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ".ToCharArray();
    private static readonly char[] LowercaseChars = "abcdefghijklmnopqrstuvwxyz".ToCharArray();
    private static readonly char[] DigitChars = "0123456789".ToCharArray();
    private static readonly char[] SymbolChars = "!@#$%^&*()-_=+[]{}|;:,.<>?".ToCharArray();

    private readonly ILogger<WindowsRotateLocalUserPasswordJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public WindowsRotateLocalUserPasswordJobExecutor(
        ILogger<WindowsRotateLocalUserPasswordJobExecutor> logger,
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
        if (!_options.EnableWindowsRotateLocalUserPasswordExecution || state.CurrentJob is null || !OperatingSystem.IsWindows())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsRotatePasswordJob(job))
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

        var username = string.IsNullOrWhiteSpace(job.RotateLocalUserPasswordUsername)
            ? "local.admin"
            : job.RotateLocalUserPasswordUsername;

        var passwordLength = job.RotateLocalUserPasswordLength is >= MinPasswordLength and <= MaxPasswordLength
            ? job.RotateLocalUserPasswordLength
            : DefaultPasswordLength;

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
            "Starting local user password rotation for job {JobId}, username '{Username}'",
            job.JobId,
            username);

        var executionResult = await RunRotatePasswordWorkflowAsync(username, passwordLength, cancellationToken);

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
            ? BuildSuccessReport(username, executionResult)
            : BuildFailureReport(
                executionResult.ErrorCode ?? "ROTATE_LOCAL_USER_PASSWORD_FAILED",
                executionResult.ErrorMessage ?? "Failed to rotate local user password.",
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
            "ROTATE_LOCAL_USER_PASSWORD_RESUME_UNSUPPORTED",
            "Agent restarted while password rotation was in progress; execution cannot be resumed safely.");

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
            "Reported password rotation completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private async Task<RotatePasswordExecutionResult> RunRotatePasswordWorkflowAsync(
        string username,
        int passwordLength,
        CancellationToken cancellationToken)
    {
        string newPassword;
        try
        {
            newPassword = GeneratePassword(passwordLength);
        }
        catch (Exception ex)
        {
            return RotatePasswordExecutionResult.Fail(
                "ROTATE_LOCAL_USER_PASSWORD_GENERATION_FAILED",
                $"Failed to generate a new password: {ex.Message}");
        }

        var script = BuildRotatePasswordScript(username, newPassword);
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
            return RotatePasswordExecutionResult.Fail(
                "ROTATE_LOCAL_USER_PASSWORD_TIMEOUT",
                "Password rotation script timed out.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        if (result.ExitCode != 0)
        {
            return RotatePasswordExecutionResult.Fail(
                "ROTATE_LOCAL_USER_PASSWORD_COMMAND_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput),
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        return RotatePasswordExecutionResult.Ok(newPassword, result.StandardOutput, result.StandardError);
    }

    private static string BuildRotatePasswordScript(string username, string newPassword)
    {
        var escapedUsername = username.Replace("'", "''", StringComparison.Ordinal);
        var escapedPassword = newPassword.Replace("'", "''", StringComparison.Ordinal);

        var sb = new StringBuilder();
        sb.AppendLine("$ErrorActionPreference = 'Stop'");
        sb.AppendLine($"$username = '{escapedUsername}'");
        sb.AppendLine($"$password = ConvertTo-SecureString '{escapedPassword}' -AsPlainText -Force");
        sb.AppendLine();
        sb.AppendLine("$existingUser = Get-LocalUser -Name $username -ErrorAction SilentlyContinue");
        sb.AppendLine("if (-not $existingUser) {");
        sb.AppendLine("    Write-Error \"User '$username' does not exist. Cannot rotate password.\"");
        sb.AppendLine("    exit 1");
        sb.AppendLine("}");
        sb.AppendLine();
        sb.AppendLine("Set-LocalUser -Name $username -Password $password");
        sb.AppendLine("Write-Output \"Password rotated successfully for user '$username'.\"");

        return sb.ToString();
    }

    private static string GeneratePassword(int length)
    {
        // Guarantee at least one character from each required class
        var requiredClasses = new[] { UppercaseChars, LowercaseChars, DigitChars, SymbolChars };
        var allChars = UppercaseChars.Concat(LowercaseChars).Concat(DigitChars).Concat(SymbolChars).ToArray();

        var passwordChars = new char[length];

        // Fill the first slots with one guaranteed character from each class
        for (var i = 0; i < requiredClasses.Length; i++)
        {
            var charSet = requiredClasses[i];
            passwordChars[i] = charSet[RandomNumberGenerator.GetInt32(charSet.Length)];
        }

        // Fill the rest from the full character set
        for (var i = requiredClasses.Length; i < length; i++)
        {
            passwordChars[i] = allChars[RandomNumberGenerator.GetInt32(allChars.Length)];
        }

        // Shuffle to avoid the guaranteed characters being in predictable positions
        RandomNumberGenerator.Shuffle(passwordChars.AsSpan());

        return new string(passwordChars);
    }

    private static bool IsRotatePasswordJob(JobExecutionState job)
    {
        return string.Equals(job.JobType, "windows_rotate_local_user_password", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "rotate_local_user_password", StringComparison.OrdinalIgnoreCase);
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "Password rotation script failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(6);

        return string.Join(" | ", lines);
    }

    private static JobCompletionReport BuildCompletionReport(JobExecutionState job)
    {
        return BuildSuccessReport(
            string.IsNullOrWhiteSpace(job.RotateLocalUserPasswordUsername)
                ? "local.admin"
                : job.RotateLocalUserPasswordUsername,
            RotatePasswordExecutionResult.Ok(string.Empty, null, null));
    }

    private static JobCompletionReport BuildSuccessReport(
        string username,
        RotatePasswordExecutionResult result)
    {
        var outputPayload = JsonSerializer.Serialize(new
        {
            username,
            new_password = result.NewPassword,
            rotated_at = DateTimeOffset.UtcNow
        });

        return new JobCompletionReport
        {
            FinalState = "Succeeded",
            InstallResult = "success",
            RebootRequired = false,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            Summary = $"Password rotated successfully for user '{username}'.",
            Output = outputPayload,
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

    private readonly record struct RotatePasswordExecutionResult(
        bool Success,
        string NewPassword,
        string? ErrorCode,
        string? ErrorMessage,
        string? StandardOutput,
        string? StandardError)
    {
        public static RotatePasswordExecutionResult Ok(
            string newPassword,
            string? standardOutput,
            string? standardError)
        {
            return new RotatePasswordExecutionResult(true, newPassword, null, null, standardOutput, standardError);
        }

        public static RotatePasswordExecutionResult Fail(
            string code,
            string message,
            string? standardOutput = null,
            string? standardError = null)
        {
            return new RotatePasswordExecutionResult(false, string.Empty, code, message, standardOutput, standardError);
        }
    }
}
