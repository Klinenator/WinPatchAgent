using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.Win32;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class SoftwareInstallJobExecutor : IJobExecutor
{
    private const int MaxReportedOutputChars = 24000;

    private readonly ILogger<SoftwareInstallJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public SoftwareInstallJobExecutor(
        ILogger<SoftwareInstallJobExecutor> logger,
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
        if (state.CurrentJob is null)
        {
            return false;
        }

        var job = state.CurrentJob;
        if (!IsSoftwareInstallJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedSoftwareInstallJobAsync(state, job, cancellationToken),
            "Installing" => await FailStaleInstallingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedSoftwareInstallJobAsync(
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

        _logger.LogInformation("Starting software install execution for job {JobId}", job.JobId);

        var executionResult = await RunSoftwareInstallWorkflowAsync(job, cancellationToken);

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
            ? BuildCompletionReport(job, executionResult)
            : BuildFailureReport(
                executionResult.ErrorCode ?? "SOFTWARE_INSTALL_FAILED",
                executionResult.ErrorMessage ?? "Software installation failed.",
                executionResult.RebootRequired,
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
            "SOFTWARE_INSTALL_RESUME_UNSUPPORTED",
            "Agent restarted while software installation was in progress; execution cannot be resumed safely.");

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
            "Reported software install completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        // Software changes should be reflected in inventory immediately.
        state.LastInventoryAtUtc = null;
        state.CurrentJob = null;
        return true;
    }

    private async Task<SoftwareInstallExecutionResult> RunSoftwareInstallWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var requestedPackages = NormalizeRequestedPackages(job.SoftwareInstallPackages);
        if (requestedPackages.Count == 0)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_NO_PACKAGES",
                "No valid software packages were provided in software_install.packages[].");
        }

        if (OperatingSystem.IsWindows())
        {
            return await RunWindowsSoftwareInstallAsync(
                requestedPackages,
                job.SoftwareInstallManager,
                job.SoftwareInstallAllowUpdate,
                cancellationToken);
        }

        if (OperatingSystem.IsLinux())
        {
            return await RunLinuxSoftwareInstallAsync(
                requestedPackages,
                job.SoftwareInstallManager,
                cancellationToken);
        }

        if (OperatingSystem.IsMacOS())
        {
            return await RunMacSoftwareInstallAsync(
                requestedPackages,
                job.SoftwareInstallManager,
                job.SoftwareInstallAllowUpdate,
                cancellationToken);
        }

        return SoftwareInstallExecutionResult.Fail(
            "SOFTWARE_INSTALL_UNSUPPORTED_OS",
            "Software install jobs are not supported on this operating system.");
    }

    private async Task<SoftwareInstallExecutionResult> RunWindowsSoftwareInstallAsync(
        IReadOnlyList<string> packages,
        string manager,
        bool allowUpdate,
        CancellationToken cancellationToken)
    {
        var normalizedManager = NormalizeWindowsManager(manager);
        if (normalizedManager == string.Empty)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_UNSUPPORTED_MANAGER",
                "Windows software installs currently support manager=winget (or auto).");
        }

        var probe = await RunProcessAsync(
            "winget",
            ["--version"],
            TimeSpan.FromSeconds(15),
            cancellationToken);
        if (probe.StartFailed || probe.ExitCode != 0)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_WINGET_NOT_FOUND",
                "winget is not available on this Windows host.",
                standardOutput: probe.StandardOutput,
                standardError: probe.StandardError);
        }

        var stdout = new StringBuilder();
        var stderr = new StringBuilder();

        foreach (var packageId in packages)
        {
            if (allowUpdate)
            {
                var upgradeResult = await RunProcessAsync(
                    "winget",
                    [
                        "upgrade",
                        "--id",
                        packageId,
                        "--exact",
                        "--silent",
                        "--accept-source-agreements",
                        "--accept-package-agreements",
                        "--disable-interactivity"
                    ],
                    TimeSpan.FromSeconds(Math.Max(30, _options.WindowsPowerShellScriptCommandTimeoutSeconds)),
                    cancellationToken);

                AppendCommandOutput(stdout, stderr, $"winget upgrade --id {packageId}", upgradeResult);

                if (upgradeResult.TimedOut)
                {
                    return SoftwareInstallExecutionResult.Fail(
                        "SOFTWARE_INSTALL_WINGET_TIMEOUT",
                        $"winget upgrade timed out for package '{packageId}'.",
                        standardOutput: stdout.ToString(),
                        standardError: stderr.ToString());
                }

                if (upgradeResult.ExitCode == 0 || IsWingetNoOpSuccess(upgradeResult.StandardOutput, upgradeResult.StandardError))
                {
                    continue;
                }

                if (!IsWingetNotInstalledMessage(upgradeResult.StandardOutput, upgradeResult.StandardError))
                {
                    return SoftwareInstallExecutionResult.Fail(
                        "SOFTWARE_INSTALL_WINGET_FAILED",
                        $"winget upgrade failed for package '{packageId}': {BuildErrorSummary(upgradeResult.StandardError, upgradeResult.StandardOutput)}",
                        standardOutput: stdout.ToString(),
                        standardError: stderr.ToString());
                }
            }

            var installResult = await RunProcessAsync(
                "winget",
                [
                    "install",
                    "--id",
                    packageId,
                    "--exact",
                    "--silent",
                    "--accept-source-agreements",
                    "--accept-package-agreements",
                    "--disable-interactivity"
                ],
                TimeSpan.FromSeconds(Math.Max(30, _options.WindowsPowerShellScriptCommandTimeoutSeconds)),
                cancellationToken);

            AppendCommandOutput(stdout, stderr, $"winget install --id {packageId}", installResult);

            if (installResult.TimedOut)
            {
                return SoftwareInstallExecutionResult.Fail(
                    "SOFTWARE_INSTALL_WINGET_TIMEOUT",
                    $"winget install timed out for package '{packageId}'.",
                    standardOutput: stdout.ToString(),
                    standardError: stderr.ToString());
            }

            if (installResult.ExitCode == 0 || IsWingetNoOpSuccess(installResult.StandardOutput, installResult.StandardError))
            {
                continue;
            }

            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_WINGET_FAILED",
                $"winget install failed for package '{packageId}': {BuildErrorSummary(installResult.StandardError, installResult.StandardOutput)}",
                standardOutput: stdout.ToString(),
                standardError: stderr.ToString());
        }

        return SoftwareInstallExecutionResult.Ok(
            $"Installed software packages via winget ({packages.Count} requested).",
            rebootRequired: IsWindowsRebootPending(),
            standardOutput: stdout.ToString(),
            standardError: stderr.ToString());
    }

    private async Task<SoftwareInstallExecutionResult> RunLinuxSoftwareInstallAsync(
        IReadOnlyList<string> packages,
        string manager,
        CancellationToken cancellationToken)
    {
        var normalizedManager = NormalizeLinuxManager(manager);
        if (normalizedManager == string.Empty)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_UNSUPPORTED_MANAGER",
                "Linux software installs currently support manager=apt (or auto).");
        }

        if (!File.Exists("/usr/bin/apt-get") && !File.Exists("/usr/bin/apt"))
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_APT_NOT_FOUND",
                "apt-get was not found on this Linux host.");
        }

        var useSudo = _options.AptUseSudoWhenNotRoot && !IsRootUser();
        var aptExecutable = useSudo ? "sudo" : "apt-get";
        var timeout = TimeSpan.FromSeconds(Math.Max(30, _options.AptCommandTimeoutSeconds));
        var stdout = new StringBuilder();
        var stderr = new StringBuilder();

        if (_options.AptRunUpdateBeforeInstall)
        {
            var updateArgs = useSudo
                ? (IReadOnlyList<string>)["-n", "apt-get", "update"]
                : ["update"];
            var updateResult = await RunProcessAsync(
                aptExecutable,
                updateArgs,
                timeout,
                cancellationToken,
                new Dictionary<string, string>
                {
                    ["DEBIAN_FRONTEND"] = "noninteractive"
                });

            AppendCommandOutput(stdout, stderr, useSudo ? "sudo -n apt-get update" : "apt-get update", updateResult);

            if (updateResult.TimedOut)
            {
                return SoftwareInstallExecutionResult.Fail(
                    "SOFTWARE_INSTALL_APT_TIMEOUT",
                    "apt-get update timed out.",
                    standardOutput: stdout.ToString(),
                    standardError: stderr.ToString());
            }

            if (updateResult.ExitCode != 0)
            {
                return SoftwareInstallExecutionResult.Fail(
                    "SOFTWARE_INSTALL_APT_FAILED",
                    BuildErrorSummary(updateResult.StandardError, updateResult.StandardOutput),
                    standardOutput: stdout.ToString(),
                    standardError: stderr.ToString());
            }
        }

        var installArgs = new List<string>();
        if (useSudo)
        {
            installArgs.AddRange(["-n", "apt-get"]);
        }

        installArgs.AddRange(["install", "-y"]);
        installArgs.AddRange(packages);

        var installResult = await RunProcessAsync(
            aptExecutable,
            installArgs,
            timeout,
            cancellationToken,
            new Dictionary<string, string>
            {
                ["DEBIAN_FRONTEND"] = "noninteractive"
            });

        AppendCommandOutput(stdout, stderr, useSudo ? "sudo -n apt-get install -y ..." : "apt-get install -y ...", installResult);

        if (installResult.TimedOut)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_APT_TIMEOUT",
                "apt-get install timed out.",
                standardOutput: stdout.ToString(),
                standardError: stderr.ToString());
        }

        if (installResult.ExitCode != 0)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_APT_FAILED",
                BuildErrorSummary(installResult.StandardError, installResult.StandardOutput),
                standardOutput: stdout.ToString(),
                standardError: stderr.ToString());
        }

        return SoftwareInstallExecutionResult.Ok(
            $"Installed software packages via apt ({packages.Count} requested).",
            rebootRequired: File.Exists("/var/run/reboot-required"),
            standardOutput: stdout.ToString(),
            standardError: stderr.ToString());
    }

    private async Task<SoftwareInstallExecutionResult> RunMacSoftwareInstallAsync(
        IReadOnlyList<string> packages,
        string manager,
        bool allowUpdate,
        CancellationToken cancellationToken)
    {
        var normalizedManager = NormalizeMacManager(manager);
        if (normalizedManager == string.Empty)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_UNSUPPORTED_MANAGER",
                "macOS software installs currently support manager=brew (or auto).");
        }

        var brewProbe = await RunProcessAsync(
            "brew",
            ["--version"],
            TimeSpan.FromSeconds(15),
            cancellationToken);
        if (brewProbe.StartFailed || brewProbe.ExitCode != 0)
        {
            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_BREW_NOT_FOUND",
                "Homebrew (brew) is not available on this macOS host.",
                standardOutput: brewProbe.StandardOutput,
                standardError: brewProbe.StandardError);
        }

        var timeout = TimeSpan.FromSeconds(Math.Max(30, _options.MacShellScriptCommandTimeoutSeconds));
        var stdout = new StringBuilder();
        var stderr = new StringBuilder();

        foreach (var requestedPackage in packages)
        {
            var (packageName, cask) = ParseMacPackageToken(requestedPackage);
            if (string.IsNullOrWhiteSpace(packageName))
            {
                continue;
            }

            if (allowUpdate)
            {
                var upgradeArgs = new List<string> { "upgrade" };
                if (cask)
                {
                    upgradeArgs.Add("--cask");
                }

                upgradeArgs.Add(packageName);

                var upgradeResult = await RunProcessAsync(
                    "brew",
                    upgradeArgs,
                    timeout,
                    cancellationToken);

                AppendCommandOutput(stdout, stderr, $"brew upgrade {(cask ? "--cask " : string.Empty)}{packageName}", upgradeResult);

                if (upgradeResult.TimedOut)
                {
                    return SoftwareInstallExecutionResult.Fail(
                        "SOFTWARE_INSTALL_BREW_TIMEOUT",
                        $"brew upgrade timed out for '{requestedPackage}'.",
                        standardOutput: stdout.ToString(),
                        standardError: stderr.ToString());
                }

                if (upgradeResult.ExitCode == 0 || IsBrewNoOpSuccess(upgradeResult.StandardOutput, upgradeResult.StandardError))
                {
                    continue;
                }

                if (!IsBrewNotInstalledMessage(upgradeResult.StandardOutput, upgradeResult.StandardError))
                {
                    return SoftwareInstallExecutionResult.Fail(
                        "SOFTWARE_INSTALL_BREW_FAILED",
                        $"brew upgrade failed for '{requestedPackage}': {BuildErrorSummary(upgradeResult.StandardError, upgradeResult.StandardOutput)}",
                        standardOutput: stdout.ToString(),
                        standardError: stderr.ToString());
                }
            }

            var installArgs = new List<string> { "install" };
            if (cask)
            {
                installArgs.Add("--cask");
            }

            installArgs.Add(packageName);

            var installResult = await RunProcessAsync(
                "brew",
                installArgs,
                timeout,
                cancellationToken);

            AppendCommandOutput(stdout, stderr, $"brew install {(cask ? "--cask " : string.Empty)}{packageName}", installResult);

            if (installResult.TimedOut)
            {
                return SoftwareInstallExecutionResult.Fail(
                    "SOFTWARE_INSTALL_BREW_TIMEOUT",
                    $"brew install timed out for '{requestedPackage}'.",
                    standardOutput: stdout.ToString(),
                    standardError: stderr.ToString());
            }

            if (installResult.ExitCode == 0 || IsBrewNoOpSuccess(installResult.StandardOutput, installResult.StandardError))
            {
                continue;
            }

            return SoftwareInstallExecutionResult.Fail(
                "SOFTWARE_INSTALL_BREW_FAILED",
                $"brew install failed for '{requestedPackage}': {BuildErrorSummary(installResult.StandardError, installResult.StandardOutput)}",
                standardOutput: stdout.ToString(),
                standardError: stderr.ToString());
        }

        return SoftwareInstallExecutionResult.Ok(
            $"Installed software packages via brew ({packages.Count} requested).",
            rebootRequired: DetectRebootRequirement(stdout.ToString(), stderr.ToString()),
            standardOutput: stdout.ToString(),
            standardError: stderr.ToString());
    }

    private static void AppendCommandOutput(
        StringBuilder stdout,
        StringBuilder stderr,
        string commandLabel,
        ProcessResult result)
    {
        stdout.AppendLine(">>> " + commandLabel);
        if (!string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            stdout.AppendLine(result.StandardOutput.Trim());
        }

        stderr.AppendLine(">>> " + commandLabel);
        if (!string.IsNullOrWhiteSpace(result.StandardError))
        {
            stderr.AppendLine(result.StandardError.Trim());
        }
        else if (result.StartFailed)
        {
            stderr.AppendLine("Process failed to start.");
        }
    }

    private static List<string> NormalizeRequestedPackages(IEnumerable<string> packages)
    {
        var normalized = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var raw in packages)
        {
            var value = (raw ?? string.Empty).Trim();
            if (!IsValidPackageToken(value))
            {
                continue;
            }

            normalized.Add(value);
        }

        return normalized.ToList();
    }

    private static bool IsValidPackageToken(string value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 128)
        {
            return false;
        }

        foreach (var character in value)
        {
            var allowed =
                char.IsLetterOrDigit(character)
                || character is '.' or '_' or '-' or ':' or '+' or '@' or '/';
            if (!allowed)
            {
                return false;
            }
        }

        return true;
    }

    private static string NormalizeWindowsManager(string manager)
    {
        var normalized = (manager ?? string.Empty).Trim().ToLowerInvariant();
        return normalized switch
        {
            "" or "auto" or "winget" => "winget",
            _ => string.Empty
        };
    }

    private static string NormalizeLinuxManager(string manager)
    {
        var normalized = (manager ?? string.Empty).Trim().ToLowerInvariant();
        return normalized switch
        {
            "" or "auto" or "apt" => "apt",
            _ => string.Empty
        };
    }

    private static string NormalizeMacManager(string manager)
    {
        var normalized = (manager ?? string.Empty).Trim().ToLowerInvariant();
        return normalized switch
        {
            "" or "auto" or "brew" => "brew",
            _ => string.Empty
        };
    }

    private static (string PackageName, bool Cask) ParseMacPackageToken(string requested)
    {
        var token = (requested ?? string.Empty).Trim();
        if (token.StartsWith("cask:", StringComparison.OrdinalIgnoreCase))
        {
            return (token["cask:".Length..].Trim(), true);
        }

        if (token.StartsWith("formula:", StringComparison.OrdinalIgnoreCase))
        {
            return (token["formula:".Length..].Trim(), false);
        }

        return (token, false);
    }

    private static bool IsWingetNotInstalledMessage(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("no installed package found matching input criteria", StringComparison.Ordinal)
            || combined.Contains("no package found installed matching input criteria", StringComparison.Ordinal);
    }

    private static bool IsWingetNoOpSuccess(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("already installed", StringComparison.Ordinal)
            || combined.Contains("no applicable update found", StringComparison.Ordinal)
            || combined.Contains("no newer package versions are available", StringComparison.Ordinal);
    }

    private static bool IsBrewNotInstalledMessage(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("not installed", StringComparison.Ordinal)
            || combined.Contains("no such keg", StringComparison.Ordinal)
            || combined.Contains("cask not installed", StringComparison.Ordinal);
    }

    private static bool IsBrewNoOpSuccess(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("already installed", StringComparison.Ordinal)
            || combined.Contains("already up-to-date", StringComparison.Ordinal)
            || combined.Contains("is up to date", StringComparison.Ordinal)
            || combined.Contains("up-to-date", StringComparison.Ordinal);
    }

    private static bool IsSoftwareInstallJob(JobExecutionState job)
    {
        if (job.SoftwareInstallPackages.Count > 0)
        {
            return true;
        }

        return string.Equals(job.JobType, "software_install", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "application_install", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "package_install", StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsRootUser()
    {
        return string.Equals(Environment.UserName, "root", StringComparison.OrdinalIgnoreCase);
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
            return "Command failed without output.";
        }

        var sanitized = source.Replace('\r', '\n');
        var lines = sanitized
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .TakeLast(8);

        return string.Join(" | ", lines);
    }

    private static JobCompletionReport BuildCompletionReport(JobExecutionState job)
    {
        return BuildCompletionReport(
            job,
            SoftwareInstallExecutionResult.Ok(
                "Software installation completed successfully.",
                rebootRequired: job.SimulatedRebootRequired,
                standardOutput: null,
                standardError: null));
    }

    private static JobCompletionReport BuildCompletionReport(
        JobExecutionState job,
        SoftwareInstallExecutionResult executionResult)
    {
        return new JobCompletionReport
        {
            FinalState = "Succeeded",
            InstallResult = "success",
            RebootRequired = job.SimulatedRebootRequired,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            Summary = executionResult.Summary,
            Output = TruncateForReport(executionResult.StandardOutput),
            ErrorOutput = TruncateForReport(executionResult.StandardError)
        };
    }

    private static JobCompletionReport BuildFailureReport(
        string code,
        string message,
        bool rebootRequired = false,
        string? standardOutput = null,
        string? standardError = null)
    {
        return new JobCompletionReport
        {
            FinalState = "Failed",
            InstallResult = "failed",
            RebootRequired = rebootRequired,
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

        var trimmed = value.Trim();
        if (trimmed.Length <= MaxReportedOutputChars)
        {
            return trimmed;
        }

        return trimmed[..MaxReportedOutputChars]
            + Environment.NewLine
            + $"... (truncated to {MaxReportedOutputChars} characters)";
    }

    private static async Task<ProcessResult> RunProcessAsync(
        string executable,
        IReadOnlyList<string> args,
        TimeSpan timeout,
        CancellationToken cancellationToken,
        IReadOnlyDictionary<string, string>? environment = null)
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

        if (environment is not null)
        {
            foreach (var pair in environment)
            {
                startInfo.Environment[pair.Key] = pair.Value;
            }
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

        try
        {
            if (!process.Start())
            {
                return new ProcessResult(-1, stdout.ToString(), stderr.ToString(), TimedOut: false, StartFailed: true);
            }
        }
        catch (Exception ex)
        {
            stderr.AppendLine(ex.Message);
            return new ProcessResult(-1, stdout.ToString(), stderr.ToString(), TimedOut: false, StartFailed: true);
        }

        process.BeginOutputReadLine();
        process.BeginErrorReadLine();

        using var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeoutCts.CancelAfter(timeout);

        try
        {
            await process.WaitForExitAsync(timeoutCts.Token);
            return new ProcessResult(process.ExitCode, stdout.ToString(), stderr.ToString(), TimedOut: false, StartFailed: false);
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            TryKill(process);
            return new ProcessResult(-1, stdout.ToString(), stderr.ToString(), TimedOut: true, StartFailed: false);
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
        bool TimedOut,
        bool StartFailed);

    private readonly record struct SoftwareInstallExecutionResult(
        bool Success,
        string Summary,
        bool RebootRequired,
        string? StandardOutput,
        string? StandardError,
        string? ErrorCode,
        string? ErrorMessage)
    {
        public static SoftwareInstallExecutionResult Ok(
            string summary,
            bool rebootRequired,
            string? standardOutput,
            string? standardError)
        {
            return new SoftwareInstallExecutionResult(
                Success: true,
                Summary: summary,
                RebootRequired: rebootRequired,
                StandardOutput: standardOutput,
                StandardError: standardError,
                ErrorCode: null,
                ErrorMessage: null);
        }

        public static SoftwareInstallExecutionResult Fail(
            string code,
            string message,
            bool rebootRequired = false,
            string? standardOutput = null,
            string? standardError = null)
        {
            return new SoftwareInstallExecutionResult(
                Success: false,
                Summary: message,
                RebootRequired: rebootRequired,
                StandardOutput: standardOutput,
                StandardError: standardError,
                ErrorCode: code,
                ErrorMessage: message);
        }
    }
}
