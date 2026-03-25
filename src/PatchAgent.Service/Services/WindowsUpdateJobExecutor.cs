using System.Diagnostics;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class WindowsUpdateJobExecutor : IJobExecutor
{
    private readonly ILogger<WindowsUpdateJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public WindowsUpdateJobExecutor(
        ILogger<WindowsUpdateJobExecutor> logger,
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
        if (!_options.EnableWindowsUpdateJobExecution || state.CurrentJob is null || !OperatingSystem.IsWindows())
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsWindowsUpdateJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedWindowsUpdateJobAsync(state, job, cancellationToken),
            "Installing" => await FailStaleInstallingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedWindowsUpdateJobAsync(
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

        _logger.LogInformation("Starting windows update execution for job {JobId}", job.JobId);

        var executionResult = await RunWindowsUpdateWorkflowAsync(job, cancellationToken);

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
                executionResult.ErrorCode ?? "WINDOWS_UPDATE_INSTALL_FAILED",
                executionResult.ErrorMessage ?? "Windows Update execution failed.");

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
            "WINDOWS_UPDATE_RESUME_UNSUPPORTED",
            "Agent restarted while Windows Update installation was in progress; execution cannot be resumed safely.");

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
            "Reported Windows update completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        // Force an inventory refresh on the next loop so Windows available/installed
        // update data is refreshed right after a patch job completes.
        state.LastInventoryAtUtc = null;
        state.CurrentJob = null;
        return true;
    }

    private async Task<WindowsUpdateExecutionResult> RunWindowsUpdateWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var result = await RunProcessAsync(
            "powershell.exe",
            [
                "-NoProfile",
                "-NonInteractive",
                "-ExecutionPolicy",
                "Bypass",
                "-Command",
                BuildWindowsUpdateScript()
            ],
            TimeSpan.FromSeconds(Math.Max(120, _options.WindowsUpdateCommandTimeoutSeconds)),
            new Dictionary<string, string>
            {
                ["PATCHAGENT_WINDOWS_UPDATE_JOB"] = JsonSerializer.Serialize(new
                {
                    install_all = job.WindowsInstallAll,
                    kbs = job.WindowsKbIds
                })
            },
            cancellationToken);

        if (result.TimedOut)
        {
            return WindowsUpdateExecutionResult.Fail(
                "WINDOWS_UPDATE_TIMEOUT",
                "Windows Update command timed out.");
        }

        if (result.ExitCode != 0 && string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            return WindowsUpdateExecutionResult.Fail(
                "WINDOWS_UPDATE_COMMAND_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput));
        }

        return ParseWindowsUpdateResult(result.StandardOutput, result.StandardError);
    }

    private static WindowsUpdateExecutionResult ParseWindowsUpdateResult(string output, string stderr)
    {
        if (string.IsNullOrWhiteSpace(output))
        {
            return WindowsUpdateExecutionResult.Fail(
                "WINDOWS_UPDATE_EMPTY_RESPONSE",
                BuildErrorSummary(stderr, output));
        }

        try
        {
            using var doc = JsonDocument.Parse(output);
            var root = doc.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
            {
                return WindowsUpdateExecutionResult.Fail(
                    "WINDOWS_UPDATE_INVALID_RESPONSE",
                    "Windows update script returned an unexpected response payload.");
            }

            var success = root.TryGetProperty("success", out var successValue)
                && successValue.ValueKind == JsonValueKind.True;
            var rebootRequired = root.TryGetProperty("reboot_required", out var rebootValue)
                && rebootValue.ValueKind == JsonValueKind.True;

            if (success)
            {
                return WindowsUpdateExecutionResult.Ok(rebootRequired);
            }

            var errorCode = root.TryGetProperty("error_code", out var codeValue)
                ? codeValue.ToString()
                : "WINDOWS_UPDATE_INSTALL_FAILED";
            var errorMessage = root.TryGetProperty("error_message", out var messageValue)
                ? messageValue.ToString()
                : "Windows Update execution failed.";

            return WindowsUpdateExecutionResult.Fail(
                string.IsNullOrWhiteSpace(errorCode) ? "WINDOWS_UPDATE_INSTALL_FAILED" : errorCode,
                string.IsNullOrWhiteSpace(errorMessage) ? "Windows Update execution failed." : errorMessage,
                rebootRequired);
        }
        catch (JsonException)
        {
            return WindowsUpdateExecutionResult.Fail(
                "WINDOWS_UPDATE_INVALID_RESPONSE",
                BuildErrorSummary(stderr, output));
        }
    }

    private static string BuildWindowsUpdateScript()
    {
        return @"
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

try {
  $jobRaw = [string]$env:PATCHAGENT_WINDOWS_UPDATE_JOB
  if ([string]::IsNullOrWhiteSpace($jobRaw)) {
    throw 'Missing PATCHAGENT_WINDOWS_UPDATE_JOB payload.'
  }

  $job = $jobRaw | ConvertFrom-Json
  $installAll = [bool]$job.install_all
  $requestedKbs = @()
  if ($job.kbs) {
    $requestedKbs = @($job.kbs | ForEach-Object { ([string]$_).Trim().ToUpperInvariant() } | Where-Object { $_ -ne '' })
  }

  $session = New-Object -ComObject Microsoft.Update.Session
  $searcher = $session.CreateUpdateSearcher()
  $searchResult = $searcher.Search(""IsInstalled=0 and IsHidden=0 and Type='Software'"")

  $updatesToInstall = New-Object -ComObject Microsoft.Update.UpdateColl

  foreach ($update in $searchResult.Updates) {
    $updateKbs = @($update.KBArticleIDs | ForEach-Object { ('KB' + $_.ToString().Trim().ToUpperInvariant()) })
    $include = $installAll

    if (-not $include -and $requestedKbs.Count -gt 0) {
      foreach ($wanted in $requestedKbs) {
        $normalizedWanted = if ($wanted.StartsWith('KB')) { $wanted } else { 'KB' + $wanted }
        if ($updateKbs -contains $normalizedWanted) {
          $include = $true
          break
        }
      }
    }

    if ($include) {
      [void]$updatesToInstall.Add($update)
    }
  }

  if ($updatesToInstall.Count -eq 0) {
    [pscustomobject]@{
      success = $false
      error_code = 'WINDOWS_UPDATE_NOT_FOUND'
      error_message = 'No matching updates are currently available.'
      reboot_required = $false
      installed = @()
    } | ConvertTo-Json -Depth 8 -Compress
    exit 0
  }

  $downloader = $session.CreateUpdateDownloader()
  $downloader.Updates = $updatesToInstall
  $downloadResult = $downloader.Download()
  $downloadCode = [int]$downloadResult.ResultCode
  if ($downloadCode -ge 4) {
    [pscustomobject]@{
      success = $false
      error_code = 'WINDOWS_UPDATE_DOWNLOAD_FAILED'
      error_message = ('Windows update download failed with code ' + $downloadCode)
      reboot_required = $false
      installed = @()
    } | ConvertTo-Json -Depth 8 -Compress
    exit 0
  }

  $installer = $session.CreateUpdateInstaller()
  $installer.Updates = $updatesToInstall
  $installResult = $installer.Install()

  $installCode = [int]$installResult.ResultCode
  $success = ($installCode -eq 2 -or $installCode -eq 3)

  $perUpdate = @()
  for ($i = 0; $i -lt $updatesToInstall.Count; $i++) {
    $update = $updatesToInstall.Item($i)
    $updateResult = $installResult.GetUpdateResult($i)
    $kbList = @($update.KBArticleIDs | ForEach-Object { ('KB' + $_.ToString().Trim().ToUpperInvariant()) })

    $perUpdate += [pscustomobject]@{
      title = [string]$update.Title
      kbs = $kbList
      result_code = [int]$updateResult.ResultCode
      hresult = ('0x{0:X8}' -f ([uint32]$updateResult.HResult))
    }
  }

  [pscustomobject]@{
    success = $success
    error_code = if ($success) { $null } else { 'WINDOWS_UPDATE_INSTALL_FAILED' }
    error_message = if ($success) { $null } else { ('Windows update install failed with code ' + $installCode) }
    reboot_required = [bool]$installResult.RebootRequired
    result_code = $installCode
    installed = $perUpdate
  } | ConvertTo-Json -Depth 8 -Compress
}
catch {
  [pscustomobject]@{
    success = $false
    error_code = 'WINDOWS_UPDATE_SCRIPT_ERROR'
    error_message = [string]$_.Exception.Message
    reboot_required = $false
    installed = @()
  } | ConvertTo-Json -Depth 8 -Compress
}
";
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "Windows update command failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(5);

        return string.Join(" | ", lines);
    }

    private static bool IsWindowsUpdateJob(JobExecutionState job)
    {
        if (job.WindowsInstallAll || job.WindowsKbIds.Count > 0)
        {
            return true;
        }

        return string.Equals(job.JobType, "windows_update_install", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "windows_patch_install", StringComparison.OrdinalIgnoreCase);
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
        IReadOnlyDictionary<string, string> environment,
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

        foreach (var variable in environment)
        {
            startInfo.Environment[variable.Key] = variable.Value;
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

    private readonly record struct WindowsUpdateExecutionResult(
        bool Success,
        bool RebootRequired,
        string? ErrorCode,
        string? ErrorMessage)
    {
        public static WindowsUpdateExecutionResult Ok(bool rebootRequired)
        {
            return new WindowsUpdateExecutionResult(true, rebootRequired, null, null);
        }

        public static WindowsUpdateExecutionResult Fail(
            string code,
            string message,
            bool rebootRequired = false)
        {
            return new WindowsUpdateExecutionResult(false, rebootRequired, code, message);
        }
    }
}
