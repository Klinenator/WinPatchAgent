using System.Diagnostics;
using System.Text;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Models;

namespace PatchAgent.Service.Services;

public sealed class SoftwareSearchJobExecutor : IJobExecutor
{
    private const int DefaultResultLimit = 25;
    private const int MinResultLimit = 1;
    private const int MaxResultLimit = 100;
    private const int MaxReportedOutputChars = 24000;

    private readonly ILogger<SoftwareSearchJobExecutor> _logger;
    private readonly AgentOptions _options;
    private readonly IPolicyClient _policyClient;
    private readonly ITelemetryQueue _telemetryQueue;

    public SoftwareSearchJobExecutor(
        ILogger<SoftwareSearchJobExecutor> logger,
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
        if (!IsSoftwareSearchJob(job))
        {
            return false;
        }

        return job.State switch
        {
            "Assigned" => await ExecuteAssignedSearchJobAsync(state, job, cancellationToken),
            "Searching" => await FailStaleSearchingJobAsync(state, job, cancellationToken),
            "Succeeded" or "Failed" => await ReportAndClearAsync(state, job, BuildCompletionReport(job), cancellationToken),
            _ => false
        };
    }

    private async Task<bool> ExecuteAssignedSearchJobAsync(
        AgentState state,
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var now = DateTimeOffset.UtcNow;
        job.State = "Searching";
        job.StateChangedAtUtc = now;
        job.ExecutionStartedAtUtc = now;
        job.PercentComplete = 10;

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "search_started",
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

        _logger.LogInformation("Starting software catalog search for job {JobId}", job.JobId);

        var executionResult = await RunSoftwareSearchWorkflowAsync(job, cancellationToken);

        job.PercentComplete = 100;
        job.StateChangedAtUtc = DateTimeOffset.UtcNow;
        job.State = executionResult.Success ? "Succeeded" : "Failed";

        await _telemetryQueue.EnqueueAsync(
            TelemetryEvent.Create(
                "search_completed",
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
                executionResult.ErrorCode ?? "SOFTWARE_SEARCH_FAILED",
                executionResult.ErrorMessage ?? "Software catalog search failed.",
                executionResult.StandardOutput,
                executionResult.StandardError);

        return await ReportAndClearAsync(state, job, completionReport, cancellationToken);
    }

    private async Task<bool> FailStaleSearchingJobAsync(
        AgentState state,
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        job.State = "Failed";
        job.StateChangedAtUtc = DateTimeOffset.UtcNow;

        var report = BuildFailureReport(
            "SOFTWARE_SEARCH_RESUME_UNSUPPORTED",
            "Agent restarted while software search was in progress; execution cannot be resumed safely.");

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
            "Reported software search completion for job {JobId} with state {FinalState}",
            job.JobId,
            report.FinalState);

        state.CurrentJob = null;
        return true;
    }

    private async Task<SoftwareSearchExecutionResult> RunSoftwareSearchWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var query = NormalizeSearchQuery(job.SoftwareSearchQuery);
        if (query == string.Empty)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_NO_QUERY",
                "No search query was provided. Set software_search.query.");
        }

        var manager = ResolveManager(job.SoftwareSearchManager);
        if (manager == string.Empty)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_UNSUPPORTED_MANAGER",
                "Search manager is not supported for this operating system. Use manager=auto or the platform default.");
        }

        var limit = NormalizeResultLimit(job.SoftwareSearchLimit);
        return manager switch
        {
            "winget" => await RunWingetSearchAsync(query, limit, cancellationToken),
            "apt" => await RunAptSearchAsync(query, limit, cancellationToken),
            "brew" => await RunBrewSearchAsync(query, limit, cancellationToken),
            _ => SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_UNSUPPORTED_MANAGER",
                "Search manager is not supported for this operating system.")
        };
    }

    private async Task<SoftwareSearchExecutionResult> RunWingetSearchAsync(
        string query,
        int limit,
        CancellationToken cancellationToken)
    {
        var probe = await RunProcessAsync(
            "winget",
            ["--version"],
            TimeSpan.FromSeconds(15),
            cancellationToken);
        if (probe.StartFailed || probe.ExitCode != 0)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_WINGET_NOT_FOUND",
                "winget is not available on this Windows host.",
                standardOutput: probe.StandardOutput,
                standardError: probe.StandardError);
        }

        var timeout = TimeSpan.FromSeconds(Math.Max(20, _options.WindowsPowerShellScriptCommandTimeoutSeconds));
        var result = await RunProcessAsync(
            "winget",
            [
                "search",
                query,
                "--accept-source-agreements"
            ],
            timeout,
            cancellationToken);

        if (result.TimedOut)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_TIMEOUT",
                $"winget search timed out for '{query}'.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        if (result.StartFailed)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_WINGET_FAILED",
                "winget search failed to start.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        if (result.ExitCode != 0 && !IsWingetNoMatchMessage(result.StandardOutput, result.StandardError))
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_WINGET_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput),
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        var noMatch = IsWingetNoMatchMessage(result.StandardOutput, result.StandardError);
        var output = new StringBuilder();
        var error = new StringBuilder();
        AppendCommandOutput(output, error, $"winget search {query}", result);

        if (noMatch)
        {
            return SoftwareSearchExecutionResult.Ok(
                $"No winget matches found for '{query}'.",
                output.ToString(),
                error.ToString());
        }

        var candidateIds = ExtractWingetCandidateIds(result.StandardOutput);
        if (candidateIds.Count > 0)
        {
            output.AppendLine();
            output.AppendLine("Top package IDs:");
            foreach (var id in candidateIds.Take(limit))
            {
                output.AppendLine("- " + id);
            }

            if (candidateIds.Count > limit)
            {
                output.AppendLine($"... and {candidateIds.Count - limit} more.");
            }

            return SoftwareSearchExecutionResult.Ok(
                $"Found {candidateIds.Count} winget match(es) for '{query}'. Use one of the IDs from output when queuing install.",
                output.ToString(),
                error.ToString());
        }

        return SoftwareSearchExecutionResult.Ok(
            $"winget search completed for '{query}'. Review command output for matches.",
            output.ToString(),
            error.ToString());
    }

    private async Task<SoftwareSearchExecutionResult> RunAptSearchAsync(
        string query,
        int limit,
        CancellationToken cancellationToken)
    {
        var probe = await RunProcessAsync(
            "apt-cache",
            ["--version"],
            TimeSpan.FromSeconds(15),
            cancellationToken);
        if (probe.StartFailed || probe.ExitCode != 0)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_APT_NOT_FOUND",
                "apt-cache is not available on this Linux host.",
                standardOutput: probe.StandardOutput,
                standardError: probe.StandardError);
        }

        var timeout = TimeSpan.FromSeconds(Math.Max(20, _options.AptCommandTimeoutSeconds));
        var result = await RunProcessAsync(
            "apt-cache",
            ["search", query],
            timeout,
            cancellationToken);

        if (result.TimedOut)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_TIMEOUT",
                $"apt-cache search timed out for '{query}'.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        if (result.StartFailed || result.ExitCode != 0)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_APT_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput),
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        var matches = result.StandardOutput
            .Replace('\r', '\n')
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(static line => line.Contains(" - ", StringComparison.Ordinal))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();

        var output = new StringBuilder();
        var error = new StringBuilder();
        AppendCommandOutput(output, error, $"apt-cache search {query}", result);

        if (matches.Count > 0)
        {
            output.AppendLine();
            output.AppendLine("Top package names:");
            foreach (var line in matches.Take(limit))
            {
                var packageName = line.Split(" - ", 2, StringSplitOptions.None)[0].Trim();
                if (packageName != string.Empty)
                {
                    output.AppendLine("- " + packageName);
                }
            }

            if (matches.Count > limit)
            {
                output.AppendLine($"... and {matches.Count - limit} more.");
            }

            return SoftwareSearchExecutionResult.Ok(
                $"Found {matches.Count} apt match(es) for '{query}'. Use package names from output when queuing install.",
                output.ToString(),
                error.ToString());
        }

        return SoftwareSearchExecutionResult.Ok(
            $"No apt matches found for '{query}'.",
            output.ToString(),
            error.ToString());
    }

    private async Task<SoftwareSearchExecutionResult> RunBrewSearchAsync(
        string query,
        int limit,
        CancellationToken cancellationToken)
    {
        var probe = await RunProcessAsync(
            "brew",
            ["--version"],
            TimeSpan.FromSeconds(15),
            cancellationToken);
        if (probe.StartFailed || probe.ExitCode != 0)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_BREW_NOT_FOUND",
                "Homebrew (brew) is not available on this macOS host.",
                standardOutput: probe.StandardOutput,
                standardError: probe.StandardError);
        }

        var timeout = TimeSpan.FromSeconds(Math.Max(20, _options.MacShellScriptCommandTimeoutSeconds));
        var result = await RunProcessAsync(
            "brew",
            ["search", query],
            timeout,
            cancellationToken);

        if (result.TimedOut)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_TIMEOUT",
                $"brew search timed out for '{query}'.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        if (result.StartFailed)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_BREW_FAILED",
                "brew search failed to start.",
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        var noMatch = IsBrewNoMatchMessage(result.StandardOutput, result.StandardError);
        if (result.ExitCode != 0 && !noMatch)
        {
            return SoftwareSearchExecutionResult.Fail(
                "SOFTWARE_SEARCH_BREW_FAILED",
                BuildErrorSummary(result.StandardError, result.StandardOutput),
                standardOutput: result.StandardOutput,
                standardError: result.StandardError);
        }

        var output = new StringBuilder();
        var error = new StringBuilder();
        AppendCommandOutput(output, error, $"brew search {query}", result);

        if (noMatch)
        {
            return SoftwareSearchExecutionResult.Ok(
                $"No brew matches found for '{query}'.",
                output.ToString(),
                error.ToString());
        }

        var lines = result.StandardOutput
            .Replace('\r', '\n')
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(static line => line != string.Empty && !line.StartsWith("==>", StringComparison.Ordinal))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();

        if (lines.Count > 0)
        {
            output.AppendLine();
            output.AppendLine("Top brew matches:");
            foreach (var line in lines.Take(limit))
            {
                output.AppendLine("- " + line);
            }

            if (lines.Count > limit)
            {
                output.AppendLine($"... and {lines.Count - limit} more.");
            }

            return SoftwareSearchExecutionResult.Ok(
                $"Found {lines.Count} brew match(es) for '{query}'. Use names from output when queuing install.",
                output.ToString(),
                error.ToString());
        }

        return SoftwareSearchExecutionResult.Ok(
            $"brew search completed for '{query}'. Review command output for matches.",
            output.ToString(),
            error.ToString());
    }

    private static string ResolveManager(string manager)
    {
        var normalized = (manager ?? string.Empty).Trim().ToLowerInvariant();
        if (normalized == string.Empty || normalized == "auto")
        {
            if (OperatingSystem.IsWindows())
            {
                return "winget";
            }

            if (OperatingSystem.IsLinux())
            {
                return "apt";
            }

            if (OperatingSystem.IsMacOS())
            {
                return "brew";
            }

            return string.Empty;
        }

        if (OperatingSystem.IsWindows() && normalized == "winget")
        {
            return "winget";
        }

        if (OperatingSystem.IsLinux() && normalized == "apt")
        {
            return "apt";
        }

        if (OperatingSystem.IsMacOS() && normalized == "brew")
        {
            return "brew";
        }

        return string.Empty;
    }

    private static bool IsSoftwareSearchJob(JobExecutionState job)
    {
        if (!string.IsNullOrWhiteSpace(job.SoftwareSearchQuery))
        {
            return true;
        }

        return string.Equals(job.JobType, "software_search", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "application_search", StringComparison.OrdinalIgnoreCase)
            || string.Equals(job.JobType, "package_search", StringComparison.OrdinalIgnoreCase);
    }

    private static string NormalizeSearchQuery(string value)
    {
        var normalized = (value ?? string.Empty).Trim();
        if (normalized.Length == 0)
        {
            return string.Empty;
        }

        if (normalized.Length > 128)
        {
            normalized = normalized[..128];
        }

        return normalized;
    }

    private static int NormalizeResultLimit(int limit)
    {
        if (limit < MinResultLimit)
        {
            return DefaultResultLimit;
        }

        if (limit > MaxResultLimit)
        {
            return MaxResultLimit;
        }

        return limit;
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

    private static bool IsWingetNoMatchMessage(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("no package found matching input criteria", StringComparison.Ordinal)
            || combined.Contains("no package found among", StringComparison.Ordinal)
            || combined.Contains("no package found in current sources", StringComparison.Ordinal)
            || combined.Contains("no package found in source", StringComparison.Ordinal)
            || (combined.Contains("no package found", StringComparison.Ordinal) && combined.Contains("matching", StringComparison.Ordinal));
    }

    private static bool IsBrewNoMatchMessage(string stdout, string stderr)
    {
        var combined = (stdout + "\n" + stderr).ToLowerInvariant();
        return combined.Contains("no formulae or casks found for", StringComparison.Ordinal)
            || combined.Contains("no formula found for", StringComparison.Ordinal)
            || combined.Contains("no cask found for", StringComparison.Ordinal);
    }

    private static List<string> ExtractWingetCandidateIds(string stdout)
    {
        var ids = new List<string>();
        if (string.IsNullOrWhiteSpace(stdout))
        {
            return ids;
        }

        var lines = stdout
            .Replace('\r', '\n')
            .Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
        if (lines.Length == 0)
        {
            return ids;
        }

        var headerIndex = -1;
        var idColumnStart = -1;
        for (var index = 0; index < lines.Length; index++)
        {
            var line = lines[index];
            if (!line.Contains("name", StringComparison.OrdinalIgnoreCase)
                || !line.Contains("id", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            var columnStart = line.IndexOf("Id", StringComparison.OrdinalIgnoreCase);
            if (columnStart < 0)
            {
                continue;
            }

            headerIndex = index;
            idColumnStart = columnStart;
            break;
        }

        if (headerIndex < 0 || idColumnStart < 0)
        {
            return ids;
        }

        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        for (var index = headerIndex + 1; index < lines.Length; index++)
        {
            var line = lines[index];
            if (line.Length <= idColumnStart)
            {
                continue;
            }

            if (IsWingetSeparatorLine(line)
                || line.StartsWith("No package found", StringComparison.OrdinalIgnoreCase)
                || line.StartsWith("The following", StringComparison.OrdinalIgnoreCase)
                || line.StartsWith("Found ", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            var idSegment = line[idColumnStart..].TrimStart();
            if (idSegment.Length == 0)
            {
                continue;
            }

            var splitIndex = idSegment.IndexOfAny([' ', '\t']);
            var candidate = (splitIndex >= 0 ? idSegment[..splitIndex] : idSegment).Trim();
            if (!IsLikelyWingetId(candidate))
            {
                continue;
            }

            if (seen.Add(candidate))
            {
                ids.Add(candidate);
            }
        }

        return ids;
    }

    private static bool IsLikelyWingetId(string value)
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

    private static bool IsWingetSeparatorLine(string line)
    {
        if (string.IsNullOrWhiteSpace(line))
        {
            return true;
        }

        foreach (var character in line)
        {
            if (character is '-' or '+' or '|' or ' ')
            {
                continue;
            }

            return false;
        }

        return true;
    }

    private static string BuildErrorSummary(string stderr, string stdout)
    {
        var source = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
        if (string.IsNullOrWhiteSpace(source))
        {
            return "Search command failed without output.";
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
            SoftwareSearchExecutionResult.Ok(
                "Software catalog search completed.",
                standardOutput: null,
                standardError: null));
    }

    private static JobCompletionReport BuildCompletionReport(
        JobExecutionState job,
        SoftwareSearchExecutionResult executionResult)
    {
        return new JobCompletionReport
        {
            FinalState = "Succeeded",
            InstallResult = "success",
            RebootRequired = false,
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

    private readonly record struct SoftwareSearchExecutionResult(
        bool Success,
        string Summary,
        string? StandardOutput,
        string? StandardError,
        string? ErrorCode,
        string? ErrorMessage)
    {
        public static SoftwareSearchExecutionResult Ok(
            string summary,
            string? standardOutput,
            string? standardError)
        {
            return new SoftwareSearchExecutionResult(
                Success: true,
                Summary: summary,
                StandardOutput: standardOutput,
                StandardError: standardError,
                ErrorCode: null,
                ErrorMessage: null);
        }

        public static SoftwareSearchExecutionResult Fail(
            string code,
            string message,
            string? standardOutput = null,
            string? standardError = null)
        {
            return new SoftwareSearchExecutionResult(
                Success: false,
                Summary: message,
                StandardOutput: standardOutput,
                StandardError: standardError,
                ErrorCode: code,
                ErrorMessage: message);
        }
    }
}
