using System.Diagnostics;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class AgentSelfUpdateJobExecutor : IJobExecutor
{
    private const string DefaultRepoUrl = "https://github.com/Klinenator/WinPatchAgent.git";
    private const string DefaultRepoRef = "main";
    private static readonly TimeSpan SelfUpdateTimeout = TimeSpan.FromMinutes(45);
    private static readonly Regex UnsafeTokenRegex = new("[^A-Za-z0-9_-]+", RegexOptions.Compiled);
    private static readonly JsonSerializerOptions MarkerJsonOptions = new()
    {
        PropertyNameCaseInsensitive = true
    };

    private readonly ILogger<AgentSelfUpdateJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPathProvider _pathProvider;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public AgentSelfUpdateJobExecutor(
        ILogger<AgentSelfUpdateJobExecutor> logger,
        IOptions<AgentOptions> options,
        IPathProvider pathProvider,
        IPolicyClient policyClient,
        ITelemetryQueue telemetryQueue)
    {
        _logger = logger;
        _options = options.Value;
        _pathProvider = pathProvider;
        _policyClient = policyClient;
        _telemetryQueue = telemetryQueue;
    }

    public async Task<bool> TryAdvanceAsync(AgentState state, CancellationToken cancellationToken)
    {
        if (state.CurrentJob is null)
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsSelfUpdateJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedSelfUpdateJobAsync(state, job, cancellationToken),
            "Installing" => await FinalizeInstallingSelfUpdateAsync(state, job, cancellationToken),
            "Succeeded" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            "Failed" => await ReportAndClearAsync(
                state,
                job,
                BuildFailureReport("AGENT_SELF_UPDATE_FAILED", "Agent self-update failed."),
                cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedSelfUpdateJobAsync(
        AgentState state,
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var now = DateTimeOffset.UtcNow;
        job.State = "Installing";
        job.StateChangedAtUtc = now;
        job.ExecutionStartedAtUtc = now;
        job.ExecutionDueAtUtc = now.Add(SelfUpdateTimeout);
        job.PercentComplete = 15;

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

        var markerPath = BuildMarkerPath(job.JobId);
        TryDeleteMarker(markerPath);

        if (await TryScheduleDetachedUpdateAsync(job, markerPath, cancellationToken))
        {
            _logger.LogInformation(
                "Scheduled detached self-update workflow for job {JobId} on {Platform}",
                job.JobId,
                DetectPlatform());
            return true;
        }

        job.State = "Failed";
        job.StateChangedAtUtc = DateTimeOffset.UtcNow;
        job.PercentComplete = 100;

        await EmitCompletionTelemetryAsync(state, job, cancellationToken);

        return await ReportAndClearAsync(
            state,
            job,
            BuildFailureReport(
                "AGENT_SELF_UPDATE_SCHEDULE_FAILED",
                "Failed to schedule detached self-update workflow."),
            cancellationToken);
    }

    private async Task<bool> FinalizeInstallingSelfUpdateAsync(
        AgentState state,
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var markerPath = BuildMarkerPath(job.JobId);
        var marker = await TryReadMarkerAsync(markerPath, cancellationToken);
        if (marker is null)
        {
            if (job.ExecutionDueAtUtc is { } dueAt && DateTimeOffset.UtcNow > dueAt)
            {
                job.State = "Failed";
                job.StateChangedAtUtc = DateTimeOffset.UtcNow;
                job.PercentComplete = 100;

                await EmitCompletionTelemetryAsync(state, job, cancellationToken);

                return await ReportAndClearAsync(
                    state,
                    job,
                    BuildFailureReport(
                        "AGENT_SELF_UPDATE_TIMEOUT",
                        "Self-update did not report completion before timeout."),
                    cancellationToken);
            }

            // Keep this job owned by the self-update executor while the detached
            // updater is still running, so fallback/stub executors cannot consume it.
            return true;
        }

        TryDeleteMarker(markerPath);

        if (!string.Equals(marker.JobId, job.JobId, StringComparison.Ordinal))
        {
            job.State = "Failed";
            job.StateChangedAtUtc = DateTimeOffset.UtcNow;
            job.PercentComplete = 100;

            await EmitCompletionTelemetryAsync(state, job, cancellationToken);

            return await ReportAndClearAsync(
                state,
                job,
                BuildFailureReport(
                    "AGENT_SELF_UPDATE_MARKER_MISMATCH",
                    "Self-update marker did not match the active job."),
                cancellationToken);
        }

        if (marker.Success)
        {
            job.State = "Succeeded";
            job.StateChangedAtUtc = DateTimeOffset.UtcNow;
            job.PercentComplete = 100;

            await EmitCompletionTelemetryAsync(state, job, cancellationToken);

            state.LastInventoryAtUtc = null;

            return await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken);
        }

        job.State = "Failed";
        job.StateChangedAtUtc = DateTimeOffset.UtcNow;
        job.PercentComplete = 100;

        await EmitCompletionTelemetryAsync(state, job, cancellationToken);

        var code = string.IsNullOrWhiteSpace(marker.ErrorCode)
            ? "AGENT_SELF_UPDATE_FAILED"
            : marker.ErrorCode.Trim();
        var message = string.IsNullOrWhiteSpace(marker.ErrorMessage)
            ? "Self-update process reported failure."
            : marker.ErrorMessage.Trim();

        return await ReportAndClearAsync(state, job, BuildFailureReport(code, message), cancellationToken);
    }

    private async Task<bool> ReportAndClearAsync(
        AgentState state,
        JobExecutionState job,
        JobCompletionReport report,
        CancellationToken cancellationToken)
    {
        await _policyClient.CompleteJobAsync(state, job, report, cancellationToken);

        _logger.LogInformation(
            "Reported self-update completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private async Task EmitCompletionTelemetryAsync(
        AgentState state,
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
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
    }

    private async Task<bool> TryScheduleDetachedUpdateAsync(
        JobExecutionState job,
        string markerPath,
        CancellationToken cancellationToken)
    {
        var repoUrl = string.IsNullOrWhiteSpace(job.AgentSelfUpdateRepoUrl)
            ? DefaultRepoUrl
            : job.AgentSelfUpdateRepoUrl.Trim();
        var repoRef = string.IsNullOrWhiteSpace(job.AgentSelfUpdateRepoRef)
            ? DefaultRepoRef
            : job.AgentSelfUpdateRepoRef.Trim();

        return OperatingSystem.IsWindows()
            ? await TryScheduleWindowsUpdateAsync(job.JobId, markerPath, repoUrl, repoRef, cancellationToken)
            : OperatingSystem.IsLinux()
                ? await TryScheduleLinuxUpdateAsync(job.JobId, markerPath, repoUrl, repoRef, cancellationToken)
                : OperatingSystem.IsMacOS()
                    ? await TryScheduleMacUpdateAsync(job.JobId, markerPath, repoUrl, repoRef, cancellationToken)
                    : false;
    }

    private async Task<bool> TryScheduleWindowsUpdateAsync(
        string jobId,
        string markerPath,
        string repoUrl,
        string repoRef,
        CancellationToken cancellationToken)
    {
        var safeToken = ToSafeToken(jobId);
        var scriptPath = Path.Combine(_pathProvider.CacheDirectory, $"self-update-{safeToken}.ps1");
        var script = BuildWindowsUpdaterScript(jobId, markerPath, repoUrl, repoRef);

        await File.WriteAllTextAsync(scriptPath, script, new UTF8Encoding(false), cancellationToken);

        var startInfo = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            UseShellExecute = false,
            CreateNoWindow = true
        };
        startInfo.ArgumentList.Add("-NoProfile");
        startInfo.ArgumentList.Add("-NonInteractive");
        startInfo.ArgumentList.Add("-ExecutionPolicy");
        startInfo.ArgumentList.Add("Bypass");
        startInfo.ArgumentList.Add("-File");
        startInfo.ArgumentList.Add(scriptPath);

        var process = Process.Start(startInfo);
        if (process is null)
        {
            _logger.LogError("Failed to spawn Windows self-update process for job {JobId}", jobId);
            return false;
        }

        _logger.LogInformation(
            "Spawned Windows self-update worker process {Pid} for job {JobId}",
            process.Id,
            jobId);

        return true;
    }

    private async Task<bool> TryScheduleLinuxUpdateAsync(
        string jobId,
        string markerPath,
        string repoUrl,
        string repoRef,
        CancellationToken cancellationToken)
    {
        var safeToken = ToSafeToken(jobId);
        var scriptPath = Path.Combine(_pathProvider.CacheDirectory, $"self-update-{safeToken}.sh");
        var script = BuildLinuxUpdaterScript(jobId, markerPath, repoUrl, repoRef);
        await File.WriteAllTextAsync(scriptPath, script, new UTF8Encoding(false), cancellationToken);

        var (exitCode, _, standardError, timedOut) = await RunProcessAsync(
            "systemd-run",
            [
                "--quiet",
                "--collect",
                "--unit",
                $"winpatchagent-selfupdate-{safeToken}",
                "/bin/bash",
                scriptPath
            ],
            TimeSpan.FromSeconds(20),
            cancellationToken);

        if (timedOut || exitCode != 0)
        {
            _logger.LogError(
                "Failed to schedule Linux self-update for job {JobId}. ExitCode={ExitCode} TimedOut={TimedOut} Error={Error}",
                jobId,
                exitCode,
                timedOut,
                standardError);
            return false;
        }

        return true;
    }

    private async Task<bool> TryScheduleMacUpdateAsync(
        string jobId,
        string markerPath,
        string repoUrl,
        string repoRef,
        CancellationToken cancellationToken)
    {
        var safeToken = ToSafeToken(jobId);
        var scriptPath = Path.Combine(_pathProvider.CacheDirectory, $"self-update-{safeToken}.sh");
        var logPath = Path.Combine(_pathProvider.LogsDirectory, $"self-update-{safeToken}.log");
        var script = BuildMacUpdaterScript(jobId, markerPath, repoUrl, repoRef);
        await File.WriteAllTextAsync(scriptPath, script, new UTF8Encoding(false), cancellationToken);

        var detachedCommand = $"nohup /bin/bash {ShellQuote(scriptPath)} > {ShellQuote(logPath)} 2>&1 &";
        var (exitCode, _, standardError, timedOut) = await RunProcessAsync(
            "/bin/bash",
            ["-lc", detachedCommand],
            TimeSpan.FromSeconds(20),
            cancellationToken);

        if (timedOut || exitCode != 0)
        {
            _logger.LogError(
                "Failed to schedule macOS self-update for job {JobId}. ExitCode={ExitCode} TimedOut={TimedOut} Error={Error}",
                jobId,
                exitCode,
                timedOut,
                standardError);
            return false;
        }

        return true;
    }

    private string BuildWindowsUpdaterScript(
        string jobId,
        string markerPath,
        string repoUrl,
        string repoRef)
    {
        var jobIdLiteral = PowerShellLiteral(jobId);
        var markerPathLiteral = PowerShellLiteral(markerPath);
        var repoUrlLiteral = PowerShellLiteral(repoUrl);
        var repoRefLiteral = PowerShellLiteral(repoRef);

        return $$"""
$ErrorActionPreference = "Stop"

$JobId = {{jobIdLiteral}}
$MarkerPath = {{markerPathLiteral}}
$RepoUrl = {{repoUrlLiteral}}
$RepoRef = {{repoRefLiteral}}
$WorkDir = "C:\ProgramData\WinPatchAgent\src"
$InstallDir = "C:\Program Files\WinPatchAgent"
$ServiceName = "PatchAgentSvc"

function Normalize-RepoHttpUrl {
    param([string]$RawUrl)

    $url = [string]($RawUrl ?? "")
    $url = $url.Trim()
    if ([string]::IsNullOrWhiteSpace($url)) {
        throw "Repo URL is empty."
    }

    $url = $url.TrimEnd("/")
    if ($url -match "^git@github\\.com:(.+?)(?:\\.git)?$") {
        return "https://github.com/$($Matches[1])"
    }

    if ($url.EndsWith(".git", [System.StringComparison]::OrdinalIgnoreCase)) {
        $url = $url.Substring(0, $url.Length - 4)
    }

    if ($url -notmatch "^https?://") {
        throw "Unsupported repo URL format: $RawUrl"
    }

    return $url
}

function Build-ArchiveUrl {
    param([string]$RawRepoUrl, [string]$RawRepoRef)
    $repoHttpUrl = Normalize-RepoHttpUrl -RawUrl $RawRepoUrl
    return "$repoHttpUrl/archive/$RawRepoRef.zip"
}

$success = $false
$errorCode = ""
$errorMessage = ""

try {
    Start-Sleep -Seconds 4

    if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) {
        throw "Missing required command: dotnet"
    }

    $workParent = Split-Path -Parent $WorkDir
    if (-not [string]::IsNullOrWhiteSpace($workParent)) {
        New-Item -ItemType Directory -Path $workParent -Force | Out-Null
    }

    $archiveUrl = Build-ArchiveUrl -RawRepoUrl $RepoUrl -RawRepoRef $RepoRef
    $archivePath = Join-Path $env:TEMP ("winpatchagent-selfupdate-" + [guid]::NewGuid().ToString("N") + ".zip")
    $extractRoot = Join-Path $env:TEMP ("winpatchagent-selfupdate-" + [guid]::NewGuid().ToString("N"))
    try {
        Invoke-WebRequest -UseBasicParsing -Uri $archiveUrl -OutFile $archivePath
        New-Item -ItemType Directory -Path $extractRoot -Force | Out-Null
        Expand-Archive -Path $archivePath -DestinationPath $extractRoot -Force

        $sourceDir = Get-ChildItem -Path $extractRoot -Directory | Select-Object -First 1
        if (-not $sourceDir) {
            throw "Downloaded archive did not contain source files."
        }

        if (Test-Path $WorkDir) {
            Remove-Item -Path $WorkDir -Recurse -Force -ErrorAction SilentlyContinue
        }

        Move-Item -Path $sourceDir.FullName -Destination $WorkDir
    } finally {
        Remove-Item -Path $archivePath -Force -ErrorAction SilentlyContinue
        Remove-Item -Path $extractRoot -Recurse -Force -ErrorAction SilentlyContinue
    }

    $service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($service) {
        Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }

    New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
    $projectPath = Join-Path $WorkDir "src\PatchAgent.Service\PatchAgent.Service.csproj"
    dotnet publish $projectPath -c Release -r win-x64 --self-contained true -o $InstallDir | Out-Null

    if (-not (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue)) {
        $exePath = Join-Path $InstallDir "PatchAgent.Service.exe"
        if (-not (Test-Path $exePath)) {
            throw "Expected service binary not found: $exePath"
        }

        sc.exe create $ServiceName binPath= "`"$exePath`"" start= auto | Out-Null
        sc.exe description $ServiceName "WinPatchAgent endpoint service" | Out-Null
    }

    Start-Service -Name $ServiceName
    $success = $true
}
catch {
    $errorCode = "WINDOWS_SELF_UPDATE_FAILED"
    $errorMessage = [string]$_.Exception.Message
    try {
        Start-Service -Name $ServiceName -ErrorAction SilentlyContinue
    } catch {
    }
}
finally {
    New-Item -ItemType Directory -Path (Split-Path -Parent $MarkerPath) -Force | Out-Null
    $payload = @{
        job_id = $JobId
        success = $success
        error_code = $errorCode
        error_message = $errorMessage
        completed_at = (Get-Date).ToUniversalTime().ToString("o")
    }
    $payload | ConvertTo-Json -Depth 5 -Compress | Set-Content -Path $MarkerPath -Encoding UTF8
}
""";
    }

    private string BuildLinuxUpdaterScript(
        string jobId,
        string markerPath,
        string repoUrl,
        string repoRef)
    {
        var jobIdLiteral = ShellQuote(jobId);
        var markerPathLiteral = ShellQuote(markerPath);
        var repoUrlLiteral = ShellQuote(repoUrl);
        var repoRefLiteral = ShellQuote(repoRef);
        var backendLiteral = ShellQuote(_options.BackendBaseUrl);
        var enrollmentLiteral = ShellQuote(_options.EnrollmentKey ?? string.Empty);

        return $$"""
#!/usr/bin/env bash
set -euo pipefail

JOB_ID={{jobIdLiteral}}
MARKER_PATH={{markerPathLiteral}}
REPO_URL={{repoUrlLiteral}}
REPO_REF={{repoRefLiteral}}
BACKEND_URL={{backendLiteral}}
ENROLLMENT_KEY={{enrollmentLiteral}}
WORK_DIR="/opt/winpatchagent-src"
SERVICE_NAME="winpatchagent"
STATUS="success"
ERROR_CODE=""
ERROR_MESSAGE=""

write_marker() {
  local success_json="false"
  if [[ "${STATUS}" == "success" ]]; then
    success_json="true"
  fi

  mkdir -p "$(dirname "${MARKER_PATH}")"
  cat > "${MARKER_PATH}" <<EOF
{"job_id":"${JOB_ID}","success":${success_json},"error_code":"${ERROR_CODE}","error_message":"${ERROR_MESSAGE}","completed_at":"$(date -u +"%Y-%m-%dT%H:%M:%SZ")"}
EOF
}

run_update() {
  sleep 4

  if ! command -v tar >/dev/null 2>&1; then
    return 10
  fi

  if ! command -v wget >/dev/null 2>&1 && ! command -v curl >/dev/null 2>&1; then
    return 11
  fi

  local repo_http="${REPO_URL%/}"
  if [[ "${repo_http}" == git@github.com:* ]]; then
    repo_http="https://github.com/${repo_http#git@github.com:}"
  fi
  repo_http="${repo_http%.git}"
  local archive_url="${repo_http}/archive/${REPO_REF}.tar.gz"
  local tmp_archive
  local tmp_extract
  tmp_archive="$(mktemp /tmp/winpatchagent-selfupdate.XXXXXX.tar.gz)"
  tmp_extract="$(mktemp -d /tmp/winpatchagent-selfupdate.XXXXXX)"

  if command -v wget >/dev/null 2>&1; then
    wget -qO "${tmp_archive}" "${archive_url}"
  else
    curl -fsSL "${archive_url}" -o "${tmp_archive}"
  fi

  tar -xzf "${tmp_archive}" -C "${tmp_extract}"
  local source_dir
  source_dir="$(find "${tmp_extract}" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
  if [[ -z "${source_dir}" || ! -x "${source_dir}/scripts/setup_ubuntu_agent.sh" ]]; then
    rm -f "${tmp_archive}"
    rm -rf "${tmp_extract}"
    return 12
  fi

  rm -rf "${WORK_DIR}"
  mv "${source_dir}" "${WORK_DIR}"
  rm -f "${tmp_archive}"
  rm -rf "${tmp_extract}"

  local cmd=(bash "${WORK_DIR}/scripts/setup_ubuntu_agent.sh" --backend-url "${BACKEND_URL}")
  if [[ -n "${ENROLLMENT_KEY}" ]]; then
    cmd+=(--enrollment-key "${ENROLLMENT_KEY}")
  fi

  "${cmd[@]}"
}

if run_update; then
  STATUS="success"
else
  STATUS="failed"
  ERROR_CODE="LINUX_SELF_UPDATE_FAILED"
  ERROR_MESSAGE="Linux self update failed."
  systemctl start "${SERVICE_NAME}" || true
fi

write_marker
""";
    }

    private string BuildMacUpdaterScript(
        string jobId,
        string markerPath,
        string repoUrl,
        string repoRef)
    {
        var jobIdLiteral = ShellQuote(jobId);
        var markerPathLiteral = ShellQuote(markerPath);
        var repoUrlLiteral = ShellQuote(repoUrl);
        var repoRefLiteral = ShellQuote(repoRef);
        var backendLiteral = ShellQuote(_options.BackendBaseUrl);
        var enrollmentLiteral = ShellQuote(_options.EnrollmentKey ?? string.Empty);
        var serviceLabel = string.IsNullOrWhiteSpace(_options.ServiceName)
            ? "com.winpatchagent.agent"
            : _options.ServiceName.Trim();
        var serviceLabelLiteral = ShellQuote(serviceLabel);

        return $$"""
#!/usr/bin/env bash
set -euo pipefail

JOB_ID={{jobIdLiteral}}
MARKER_PATH={{markerPathLiteral}}
REPO_URL={{repoUrlLiteral}}
REPO_REF={{repoRefLiteral}}
BACKEND_URL={{backendLiteral}}
ENROLLMENT_KEY={{enrollmentLiteral}}
SERVICE_LABEL={{serviceLabelLiteral}}
WORK_DIR="/opt/winpatchagent-src"
STATUS="success"
ERROR_CODE=""
ERROR_MESSAGE=""

write_marker() {
  local success_json="false"
  if [[ "${STATUS}" == "success" ]]; then
    success_json="true"
  fi

  mkdir -p "$(dirname "${MARKER_PATH}")"
  cat > "${MARKER_PATH}" <<EOF
{"job_id":"${JOB_ID}","success":${success_json},"error_code":"${ERROR_CODE}","error_message":"${ERROR_MESSAGE}","completed_at":"$(date -u +"%Y-%m-%dT%H:%M:%SZ")"}
EOF
}

run_update() {
  sleep 4

  if ! command -v tar >/dev/null 2>&1; then
    return 10
  fi

  if ! command -v wget >/dev/null 2>&1 && ! command -v curl >/dev/null 2>&1; then
    return 11
  fi

  local repo_http="${REPO_URL%/}"
  if [[ "${repo_http}" == git@github.com:* ]]; then
    repo_http="https://github.com/${repo_http#git@github.com:}"
  fi
  repo_http="${repo_http%.git}"
  local archive_url="${repo_http}/archive/${REPO_REF}.tar.gz"
  local tmp_archive
  local tmp_extract
  tmp_archive="$(mktemp /tmp/winpatchagent-selfupdate.XXXXXX.tar.gz)"
  tmp_extract="$(mktemp -d /tmp/winpatchagent-selfupdate.XXXXXX)"

  if command -v wget >/dev/null 2>&1; then
    wget -qO "${tmp_archive}" "${archive_url}"
  else
    curl -fsSL "${archive_url}" -o "${tmp_archive}"
  fi

  tar -xzf "${tmp_archive}" -C "${tmp_extract}"
  local source_dir
  source_dir="$(find "${tmp_extract}" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
  if [[ -z "${source_dir}" || ! -x "${source_dir}/scripts/setup_macos_agent.sh" ]]; then
    rm -f "${tmp_archive}"
    rm -rf "${tmp_extract}"
    return 12
  fi

  rm -rf "${WORK_DIR}"
  mv "${source_dir}" "${WORK_DIR}"
  rm -f "${tmp_archive}"
  rm -rf "${tmp_extract}"

  local cmd=(bash "${WORK_DIR}/scripts/setup_macos_agent.sh" --backend-url "${BACKEND_URL}" --service-label "${SERVICE_LABEL}")
  if [[ -n "${ENROLLMENT_KEY}" ]]; then
    cmd+=(--enrollment-key "${ENROLLMENT_KEY}")
  fi

  "${cmd[@]}"
}

if run_update; then
  STATUS="success"
else
  STATUS="failed"
  ERROR_CODE="MAC_SELF_UPDATE_FAILED"
  ERROR_MESSAGE="macOS self update failed."
fi

write_marker
""";
    }

    private async Task<SelfUpdateMarker?> TryReadMarkerAsync(string markerPath, CancellationToken cancellationToken)
    {
        if (!File.Exists(markerPath))
        {
            return null;
        }

        try
        {
            var raw = await File.ReadAllTextAsync(markerPath, cancellationToken);
            if (string.IsNullOrWhiteSpace(raw))
            {
                return null;
            }

            return JsonSerializer.Deserialize<SelfUpdateMarker>(raw, MarkerJsonOptions);
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Failed to parse self-update marker file: {MarkerPath}", markerPath);
            return new SelfUpdateMarker
            {
                JobId = Path.GetFileNameWithoutExtension(markerPath),
                Success = false,
                ErrorCode = "AGENT_SELF_UPDATE_MARKER_PARSE_FAILED",
                ErrorMessage = "Self-update marker file could not be parsed."
            };
        }
    }

    private string BuildMarkerPath(string jobId)
    {
        var safeToken = ToSafeToken(jobId);
        return Path.Combine(_pathProvider.StateDirectory, $"self-update-{safeToken}.json");
    }

    private static string ToSafeToken(string value)
    {
        var trimmed = string.IsNullOrWhiteSpace(value) ? "job" : value.Trim();
        var safe = UnsafeTokenRegex.Replace(trimmed, "_");
        safe = safe.Trim('_');
        return string.IsNullOrWhiteSpace(safe) ? "job" : safe;
    }

    private static string DetectPlatform()
    {
        if (OperatingSystem.IsWindows())
        {
            return "windows";
        }

        if (OperatingSystem.IsLinux())
        {
            return "linux";
        }

        if (OperatingSystem.IsMacOS())
        {
            return "mac";
        }

        return "unknown";
    }

    private static string PowerShellLiteral(string value)
    {
        return "'" + (value ?? string.Empty).Replace("'", "''", StringComparison.Ordinal) + "'";
    }

    private static string ShellQuote(string value)
    {
        return "'" + (value ?? string.Empty).Replace("'", "'\"'\"'", StringComparison.Ordinal) + "'";
    }

    private static void TryDeleteMarker(string markerPath)
    {
        try
        {
            if (File.Exists(markerPath))
            {
                File.Delete(markerPath);
            }
        }
        catch
        {
        }
    }

    private static bool IsSelfUpdateJob(JobExecutionState job)
    {
        if (!string.IsNullOrWhiteSpace(job.AgentSelfUpdateRepoUrl)
            || !string.IsNullOrWhiteSpace(job.AgentSelfUpdateRepoRef))
        {
            return true;
        }

        return string.Equals(job.JobType, "agent_self_update", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "self_update", StringComparison.OrdinalIgnoreCase);
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
        string message)
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

    private sealed class SelfUpdateMarker
    {
        [JsonPropertyName("job_id")]
        public string JobId { get; set; } = string.Empty;

        [JsonPropertyName("success")]
        public bool Success { get; set; }

        [JsonPropertyName("error_code")]
        public string ErrorCode { get; set; } = string.Empty;

        [JsonPropertyName("error_message")]
        public string ErrorMessage { get; set; } = string.Empty;
    }

    private readonly record struct ProcessResult(
        int ExitCode,
        string StandardOutput,
        string StandardError,
        bool TimedOut);
}
