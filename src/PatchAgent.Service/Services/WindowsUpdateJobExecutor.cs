using System.Globalization;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
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
    private const string SearchCriteria = "IsInstalled=0 and IsHidden=0 and Type='Software'";

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

    [SupportedOSPlatform("windows")]
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

        _logger.LogInformation("Starting native Windows Update execution for job {JobId}", job.JobId);

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
            ? BuildCompletionReport(executionResult)
            : BuildFailureReport(
                executionResult.ErrorCode ?? "WINDOWS_UPDATE_INSTALL_FAILED",
                executionResult.ErrorMessage ?? "Windows Update execution failed.",
                executionResult.RebootRequired,
                executionResult.Summary,
                executionResult.Output,
                executionResult.ErrorOutput);

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

        state.LastInventoryAtUtc = null;
        state.CurrentJob = null;
        return true;
    }

    [SupportedOSPlatform("windows")]
    private async Task<WindowsUpdateExecutionResult> RunWindowsUpdateWorkflowAsync(
        JobExecutionState job,
        CancellationToken cancellationToken)
    {
        var timeout = TimeSpan.FromSeconds(Math.Max(120, _options.WindowsUpdateCommandTimeoutSeconds));

        try
        {
            return await RunOnStaThreadAsync(
                () => ExecuteWindowsUpdateWorkflow(job, timeout, cancellationToken),
                cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (TimeoutException exception)
        {
            return WindowsUpdateExecutionResult.Fail(
                "WINDOWS_UPDATE_TIMEOUT",
                exception.Message,
                summary: exception.Message,
                errorOutput: exception.ToString());
        }
        catch (Exception exception)
        {
            _logger.LogError(exception, "Native Windows Update execution failed for job {JobId}", job.JobId);
            return WindowsUpdateExecutionResult.Fail(
                "WINDOWS_UPDATE_EXECUTOR_ERROR",
                exception.Message,
                summary: "Native Windows Update execution failed.",
                errorOutput: exception.ToString());
        }
    }

    [SupportedOSPlatform("windows")]
    private WindowsUpdateExecutionResult ExecuteWindowsUpdateWorkflow(
        JobExecutionState job,
        TimeSpan timeout,
        CancellationToken cancellationToken)
    {
        var startedAt = DateTimeOffset.UtcNow;
        var requestedKbs = job.WindowsKbIds
            .Select(NormalizeKbId)
            .Where(static value => value != string.Empty)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
        var trackedComObjects = new List<object>();

        static object RequireInstance(object? instance, string label)
        {
            return instance ?? throw new InvalidOperationException(label + " returned a null COM instance.");
        }

        object TrackComObject(object instance)
        {
            if (Marshal.IsComObject(instance))
            {
                trackedComObjects.Add(instance);
            }

            return instance;
        }

        try
        {
            cancellationToken.ThrowIfCancellationRequested();

            var session = TrackComObject(RequireInstance(CreateComObject("Microsoft.Update.Session"), "Microsoft.Update.Session"));
            var searcher = TrackComObject(InvokeComMethod<object>(session, "CreateUpdateSearcher"));
            var searchResult = TrackComObject(InvokeComMethod<object>(searcher, "Search", SearchCriteria));
            var availableUpdates = TrackComObject(GetComProperty<object>(searchResult, "Updates"));
            var updatesToInstall = TrackComObject(RequireInstance(CreateComObject("Microsoft.Update.UpdateColl"), "Microsoft.Update.UpdateColl"));

            var selectedUpdates = new List<SelectedWindowsUpdate>();
            var availableCount = GetComProperty<int>(availableUpdates, "Count");

            for (var index = 0; index < availableCount; index++)
            {
                cancellationToken.ThrowIfCancellationRequested();

                var update = InvokeComMethod<object>(availableUpdates, "Item", index);
                try
                {
                    var title = GetComProperty<string>(update, "Title");
                    var kbIds = ReadKbArticleIds(update);
                    var include = job.WindowsInstallAll || ShouldIncludeUpdate(kbIds, requestedKbs);
                    if (!include)
                    {
                        continue;
                    }

                    InvokeComMethod<object>(updatesToInstall, "Add", update);
                    selectedUpdates.Add(new SelectedWindowsUpdate(title, kbIds));
                }
                finally
                {
                    ReleaseComObject(update);
                }
            }

            if (selectedUpdates.Count == 0)
            {
                var message = "No matching updates are currently available.";
                return WindowsUpdateExecutionResult.Fail(
                    "WINDOWS_UPDATE_NOT_FOUND",
                    message,
                    summary: message,
                    output: JsonSerializer.Serialize(new
                    {
                        requested_kbs = requestedKbs.OrderBy(static value => value).ToArray(),
                        search_criteria = SearchCriteria,
                        available_updates = availableCount
                    }));
            }

            ThrowIfTimedOut(startedAt, timeout, "searching for applicable updates");

            var downloader = TrackComObject(InvokeComMethod<object>(session, "CreateUpdateDownloader"));
            SetComProperty(downloader, "Updates", updatesToInstall);
            var downloadResult = TrackComObject(InvokeComMethod<object>(downloader, "Download"));
            var downloadCode = GetComProperty<int>(downloadResult, "ResultCode");

            if (downloadCode >= 4)
            {
                var message = "Windows update download failed with code " + downloadCode + " (" + DescribeResultCode(downloadCode) + ").";
                return WindowsUpdateExecutionResult.Fail(
                    "WINDOWS_UPDATE_DOWNLOAD_FAILED",
                    message,
                    summary: message,
                    output: JsonSerializer.Serialize(new
                    {
                        requested_kbs = requestedKbs.OrderBy(static value => value).ToArray(),
                        selected_updates = selectedUpdates,
                        download_result_code = downloadCode,
                        download_result_label = DescribeResultCode(downloadCode)
                    }));
            }

            ThrowIfTimedOut(startedAt, timeout, "downloading updates");

            var installer = TrackComObject(InvokeComMethod<object>(session, "CreateUpdateInstaller"));
            SetComProperty(installer, "Updates", updatesToInstall);
            var installResult = TrackComObject(InvokeComMethod<object>(installer, "Install"));
            var installCode = GetComProperty<int>(installResult, "ResultCode");
            var rebootRequired = GetComProperty<bool>(installResult, "RebootRequired");
            var success = installCode is 2 or 3;

            var perUpdateResults = CollectPerUpdateResults(updatesToInstall, installResult, cancellationToken);
            var summary = success
                ? BuildSuccessSummary(selectedUpdates.Count, perUpdateResults, rebootRequired)
                : "Windows update install failed with code " + installCode + " (" + DescribeResultCode(installCode) + ").";
            var output = JsonSerializer.Serialize(new
            {
                requested_kbs = requestedKbs.OrderBy(static value => value).ToArray(),
                selected_updates = selectedUpdates,
                download_result_code = downloadCode,
                download_result_label = DescribeResultCode(downloadCode),
                install_result_code = installCode,
                install_result_label = DescribeResultCode(installCode),
                reboot_required = rebootRequired,
                installed = perUpdateResults
            });

            if (success)
            {
                return WindowsUpdateExecutionResult.Ok(rebootRequired, summary, output);
            }

            return WindowsUpdateExecutionResult.Fail(
                "WINDOWS_UPDATE_INSTALL_FAILED",
                summary,
                rebootRequired,
                summary,
                output,
                BuildPerUpdateFailureDetails(perUpdateResults));
        }
        finally
        {
            for (var index = trackedComObjects.Count - 1; index >= 0; index--)
            {
                ReleaseComObject(trackedComObjects[index]);
            }
        }
    }

    [SupportedOSPlatform("windows")]
    private static List<InstalledWindowsUpdateResult> CollectPerUpdateResults(
        object updatesToInstall,
        object installResult,
        CancellationToken cancellationToken)
    {
        var results = new List<InstalledWindowsUpdateResult>();
        var selectedCount = GetComProperty<int>(updatesToInstall, "Count");

        for (var index = 0; index < selectedCount; index++)
        {
            cancellationToken.ThrowIfCancellationRequested();

            var update = InvokeComMethod<object>(updatesToInstall, "Item", index);
            var updateResult = InvokeComMethod<object>(installResult, "GetUpdateResult", index);
            try
            {
                var kbIds = ReadKbArticleIds(update);
                var title = GetComProperty<string>(update, "Title");
                var resultCode = GetComProperty<int>(updateResult, "ResultCode");
                var hResult = GetComProperty<int>(updateResult, "HResult");

                results.Add(new InstalledWindowsUpdateResult(
                    title,
                    kbIds,
                    resultCode,
                    DescribeResultCode(resultCode),
                    "0x" + unchecked((uint)hResult).ToString("X8", CultureInfo.InvariantCulture)));
            }
            finally
            {
                ReleaseComObject(updateResult);
                ReleaseComObject(update);
            }
        }

        return results;
    }

    private static bool ShouldIncludeUpdate(IEnumerable<string> updateKbIds, IReadOnlySet<string> requestedKbs)
    {
        if (requestedKbs.Count == 0)
        {
            return false;
        }

        foreach (var kbId in updateKbIds)
        {
            if (requestedKbs.Contains(kbId))
            {
                return true;
            }
        }

        return false;
    }

    private static string BuildSuccessSummary(
        int selectedCount,
        IReadOnlyCollection<InstalledWindowsUpdateResult> perUpdateResults,
        bool rebootRequired)
    {
        var installedCount = perUpdateResults.Count(result => result.ResultCode is 2 or 3);
        return "Installed "
            + installedCount
            + " of "
            + selectedCount
            + " selected update(s). Reboot required: "
            + (rebootRequired ? "yes" : "no")
            + ".";
    }

    private static string BuildPerUpdateFailureDetails(IEnumerable<InstalledWindowsUpdateResult> perUpdateResults)
    {
        var builder = new StringBuilder();
        foreach (var result in perUpdateResults.Where(static item => item.ResultCode is not 2 and not 3))
        {
            if (builder.Length > 0)
            {
                builder.Append(" | ");
            }

            builder.Append(result.Title);
            if (result.Kbs.Count > 0)
            {
                builder.Append(" [");
                builder.Append(string.Join(", ", result.Kbs));
                builder.Append(']');
            }
            builder.Append(": ");
            builder.Append(result.ResultLabel);
            builder.Append(" (");
            builder.Append(result.HResult);
            builder.Append(')');
        }

        return builder.Length == 0
            ? "Windows update install failed without per-update error details."
            : builder.ToString();
    }

    [SupportedOSPlatform("windows")]
    private static async Task<T> RunOnStaThreadAsync<T>(Func<T> action, CancellationToken cancellationToken)
    {
        var completion = new TaskCompletionSource<T>(TaskCreationOptions.RunContinuationsAsynchronously);
        var thread = new Thread(() =>
        {
            try
            {
                completion.SetResult(action());
            }
            catch (Exception exception)
            {
                completion.SetException(exception);
            }
        })
        {
            IsBackground = true,
            Name = "PatchAgent.WindowsUpdateExecutor"
        };

        thread.SetApartmentState(ApartmentState.STA);
        thread.Start();

        return await completion.Task.WaitAsync(cancellationToken);
    }

    [SupportedOSPlatform("windows")]
    private static object CreateComObject(string progId)
    {
        var type = Type.GetTypeFromProgID(progId, throwOnError: true)
            ?? throw new InvalidOperationException("Could not resolve COM ProgID " + progId + ".");
        return Activator.CreateInstance(type)
            ?? throw new InvalidOperationException("Could not create COM instance for " + progId + ".");
    }

    [SupportedOSPlatform("windows")]
    private static T GetComProperty<T>(object target, string propertyName)
    {
        var value = target.GetType().InvokeMember(
            propertyName,
            BindingFlags.Instance | BindingFlags.Public | BindingFlags.GetProperty,
            binder: null,
            target,
            args: null);

        return ConvertComValue<T>(value, propertyName);
    }

    [SupportedOSPlatform("windows")]
    private static void SetComProperty(object target, string propertyName, object? value)
    {
        target.GetType().InvokeMember(
            propertyName,
            BindingFlags.Instance | BindingFlags.Public | BindingFlags.SetProperty,
            binder: null,
            target,
            args: [value]);
    }

    [SupportedOSPlatform("windows")]
    private static T InvokeComMethod<T>(object target, string methodName, params object?[] args)
    {
        var value = target.GetType().InvokeMember(
            methodName,
            BindingFlags.Instance | BindingFlags.Public | BindingFlags.InvokeMethod,
            binder: null,
            target,
            args);

        return ConvertComValue<T>(value, methodName);
    }

    private static T ConvertComValue<T>(object? value, string label)
    {
        if (value is T typedValue)
        {
            return typedValue;
        }

        if (typeof(T) == typeof(object))
        {
            return (T)(value ?? throw new InvalidOperationException(label + " returned null."));
        }

        if (value is null)
        {
            throw new InvalidOperationException(label + " returned null.");
        }

        var targetType = Nullable.GetUnderlyingType(typeof(T)) ?? typeof(T);
        return (T)Convert.ChangeType(value, targetType, CultureInfo.InvariantCulture);
    }

    [SupportedOSPlatform("windows")]
    private static List<string> ReadKbArticleIds(object update)
    {
        var raw = GetComProperty<object>(update, "KBArticleIDs");
        return EnumerateComStrings(raw)
            .Select(NormalizeKbId)
            .Where(static value => value != string.Empty)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();
    }

    private static IEnumerable<string> EnumerateComStrings(object? value)
    {
        if (value is null)
        {
            yield break;
        }

        if (value is string single)
        {
            var normalized = single.Trim();
            if (normalized != string.Empty)
            {
                yield return normalized;
            }

            yield break;
        }

        if (value is Array array)
        {
            foreach (var item in array)
            {
                var normalized = Convert.ToString(item, CultureInfo.InvariantCulture)?.Trim();
                if (!string.IsNullOrWhiteSpace(normalized))
                {
                    yield return normalized;
                }
            }
        }
    }

    private static string NormalizeKbId(string value)
    {
        var trimmed = value.Trim().ToUpperInvariant();
        if (trimmed == string.Empty)
        {
            return string.Empty;
        }

        return trimmed.StartsWith("KB", StringComparison.Ordinal) ? trimmed : "KB" + trimmed;
    }

    private static void ThrowIfTimedOut(DateTimeOffset startedAt, TimeSpan timeout, string phase)
    {
        if (DateTimeOffset.UtcNow - startedAt <= timeout)
        {
            return;
        }

        throw new TimeoutException("Windows Update exceeded the configured timeout while " + phase + ".");
    }

    [SupportedOSPlatform("windows")]
    private static void ReleaseComObject(object? instance)
    {
        if (instance is null || !Marshal.IsComObject(instance))
        {
            return;
        }

        try
        {
            Marshal.FinalReleaseComObject(instance);
        }
        catch
        {
        }
    }

    private static string DescribeResultCode(int code)
    {
        return code switch
        {
            0 => "not_started",
            1 => "in_progress",
            2 => "succeeded",
            3 => "succeeded_with_errors",
            4 => "failed",
            5 => "aborted",
            _ => "unknown(" + code.ToString(CultureInfo.InvariantCulture) + ")"
        };
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

    private static JobCompletionReport BuildCompletionReport(WindowsUpdateExecutionResult result)
    {
        return new JobCompletionReport
        {
            FinalState = "Succeeded",
            InstallResult = "success",
            RebootRequired = result.RebootRequired,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            Summary = result.Summary,
            Output = result.Output,
            ErrorOutput = result.ErrorOutput
        };
    }

    private static JobCompletionReport BuildFailureReport(
        string code,
        string message,
        bool rebootRequired = false,
        string? summary = null,
        string? output = null,
        string? errorOutput = null)
    {
        return new JobCompletionReport
        {
            FinalState = "Failed",
            InstallResult = "failed",
            RebootRequired = rebootRequired,
            RebootPerformed = false,
            PostRebootValidation = "not_run",
            Summary = summary,
            Output = output,
            ErrorOutput = errorOutput,
            ErrorCode = code,
            ErrorMessage = message,
            Retryable = true
        };
    }

    private sealed record SelectedWindowsUpdate(
        string Title,
        List<string> Kbs);

    private sealed record InstalledWindowsUpdateResult(
        string Title,
        List<string> Kbs,
        int ResultCode,
        string ResultLabel,
        string HResult);

    private readonly record struct WindowsUpdateExecutionResult(
        bool Success,
        bool RebootRequired,
        string? ErrorCode,
        string? ErrorMessage,
        string? Summary,
        string? Output,
        string? ErrorOutput)
    {
        public static WindowsUpdateExecutionResult Ok(
            bool rebootRequired,
            string? summary = null,
            string? output = null,
            string? errorOutput = null)
        {
            return new WindowsUpdateExecutionResult(true, rebootRequired, null, null, summary, output, errorOutput);
        }

        public static WindowsUpdateExecutionResult Fail(
            string code,
            string message,
            bool rebootRequired = false,
            string? summary = null,
            string? output = null,
            string? errorOutput = null)
        {
            return new WindowsUpdateExecutionResult(false, rebootRequired, code, message, summary, output, errorOutput);
        }
    }
}
