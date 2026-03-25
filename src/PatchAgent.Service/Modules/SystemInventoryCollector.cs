using Microsoft.Extensions.Logging;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace PatchAgent.Service.Modules;

public sealed class SystemInventoryCollector : IInventoryCollector
{
    private readonly ILogger<SystemInventoryCollector> _logger;
    private readonly IPathProvider _pathProvider;

    public SystemInventoryCollector(
        ILogger<SystemInventoryCollector> logger,
        IPathProvider pathProvider)
    {
        _logger = logger;
        _pathProvider = pathProvider;
    }

    public async Task<InventorySnapshot> CollectAsync(AgentState state, CancellationToken cancellationToken)
    {
        var rootPath = Path.GetPathRoot(_pathProvider.RootPath);
        long? freeDiskMb = null;

        if (!string.IsNullOrWhiteSpace(rootPath))
        {
            var driveInfo = new DriveInfo(rootPath);
            if (driveInfo.IsReady)
            {
                freeDiskMb = driveInfo.AvailableFreeSpace / (1024 * 1024);
            }
        }

        var snapshot = new InventorySnapshot
        {
            CollectedAtUtc = DateTimeOffset.UtcNow,
            FreeDiskMb = freeDiskMb
        };

        if (OperatingSystem.IsWindows())
        {
            snapshot.PendingReboot = IsWindowsRebootPending();
            snapshot.InstalledWindowsPatches = await CollectInstalledWindowsPatchesAsync(cancellationToken);
            snapshot.AvailableWindowsPatches = await CollectAvailableWindowsPatchesAsync(cancellationToken);
        }
        else if (OperatingSystem.IsLinux())
        {
            snapshot.PendingReboot = File.Exists("/var/run/reboot-required");
            snapshot.AptAvailable = File.Exists("/usr/bin/apt-get") || File.Exists("/usr/bin/apt");
            snapshot.LinuxKernelVersion = ReadLinuxKernelVersion();

            var osRelease = ParseOsRelease();
            if (osRelease.TryGetValue("ID", out var distroId))
            {
                snapshot.LinuxDistroId = distroId;
            }

            if (osRelease.TryGetValue("VERSION_ID", out var versionId))
            {
                snapshot.LinuxDistroVersionId = versionId;
            }
        }
        else if (OperatingSystem.IsMacOS())
        {
            snapshot.PendingReboot = false;
            snapshot.MacOsProductVersion = await ReadMacSwVersValueAsync("-productVersion", cancellationToken);
            snapshot.MacOsBuildVersion = await ReadMacSwVersValueAsync("-buildVersion", cancellationToken);

            var macOsUpdateSnapshot = await CollectMacAvailableUpdatesAsync(cancellationToken);
            snapshot.MacSoftwareUpdateAvailable = macOsUpdateSnapshot.SoftwareUpdateAvailable;
            snapshot.MacAvailableUpdateLabels = macOsUpdateSnapshot.AvailableUpdateLabels;
        }

        _logger.LogInformation(
            "Collected inventory snapshot for device {DeviceId} on host {Hostname}",
            state.DeviceId,
            snapshot.Hostname);

        return snapshot;
    }

    private async Task<List<InstalledPatchSnapshot>> CollectInstalledWindowsPatchesAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
        {
            return [];
        }

        var script = @"
$ErrorActionPreference = 'SilentlyContinue'
$items = @()
try {
  $items = Get-HotFix | Sort-Object -Property InstalledOn -Descending | Select-Object -First 300 HotFixID, Description, InstalledOn
} catch {
  $items = @()
}

$normalized = @(
  $items | ForEach-Object {
    $installedAt = ''
    if ($_.InstalledOn) {
      try {
        $installedAt = (Get-Date $_.InstalledOn).ToString('o')
      } catch {
        $installedAt = [string]$_.InstalledOn
      }
    }

    [PSCustomObject]@{
      kb = [string]$_.HotFixID
      title = [string]$_.Description
      installed_at = $installedAt
    }
  }
)

$normalized | ConvertTo-Json -Depth 4 -Compress
";

        var result = await RunProcessAsync(
            "powershell.exe",
            [
                "-NoProfile",
                "-NonInteractive",
                "-ExecutionPolicy",
                "Bypass",
                "-Command",
                script
            ],
            TimeSpan.FromSeconds(30),
            cancellationToken);

        if (result.ExitCode != 0 || string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            _logger.LogDebug(
                "Windows patch inventory query failed with exit code {ExitCode}: {Error}",
                result.ExitCode,
                result.StandardError);
            return [];
        }

        return ParsePatchInventoryJson(result.StandardOutput);
    }

    private async Task<List<AvailablePatchSnapshot>> CollectAvailableWindowsPatchesAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
        {
            return [];
        }

        var script = @"
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$session = New-Object -ComObject Microsoft.Update.Session
$searcher = $session.CreateUpdateSearcher()
$searchResult = $searcher.Search(""IsInstalled=0 and IsHidden=0 and Type='Software'"")

$items = @(
  foreach ($update in $searchResult.Updates) {
    $kbIds = @($update.KBArticleIDs | ForEach-Object {
      $id = [string]$_
      if (-not [string]::IsNullOrWhiteSpace($id)) {
        $normalized = $id.Trim().ToUpperInvariant()
        if (-not $normalized.StartsWith('KB')) { $normalized = 'KB' + $normalized }
        $normalized
      }
    } | Where-Object { $_ -ne $null -and $_ -ne '' })

    $updateId = ''
    if ($kbIds.Count -gt 0) {
      $updateId = $kbIds[0]
    } elseif ($update.Identity -and $update.Identity.UpdateID) {
      $updateId = [string]$update.Identity.UpdateID
    }

    [PSCustomObject]@{
      update_id = $updateId
      title = [string]$update.Title
    }
  }
)

$items | ConvertTo-Json -Depth 5 -Compress
";

        var result = await RunProcessAsync(
            "powershell.exe",
            [
                "-NoProfile",
                "-NonInteractive",
                "-ExecutionPolicy",
                "Bypass",
                "-Command",
                script
            ],
            TimeSpan.FromSeconds(45),
            cancellationToken);

        if (result.ExitCode != 0 || string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            _logger.LogDebug(
                "Windows available update query failed with exit code {ExitCode}: {Error}",
                result.ExitCode,
                result.StandardError);
            return [];
        }

        return ParseAvailablePatchInventoryJson(result.StandardOutput);
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

    private static List<InstalledPatchSnapshot> ParsePatchInventoryJson(string rawJson)
    {
        var patches = new List<InstalledPatchSnapshot>();

        try
        {
            using var document = JsonDocument.Parse(rawJson);
            var root = document.RootElement;

            if (root.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in root.EnumerateArray())
                {
                    AddPatch(item, patches);
                }

                return patches;
            }

            if (root.ValueKind == JsonValueKind.Object)
            {
                AddPatch(root, patches);
            }
        }
        catch
        {
            return [];
        }

        return patches;
    }

    private static List<AvailablePatchSnapshot> ParseAvailablePatchInventoryJson(string rawJson)
    {
        var patches = new List<AvailablePatchSnapshot>();

        try
        {
            using var document = JsonDocument.Parse(rawJson);
            var root = document.RootElement;

            if (root.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in root.EnumerateArray())
                {
                    AddAvailablePatch(item, patches);
                }

                return patches;
            }

            if (root.ValueKind == JsonValueKind.Object)
            {
                AddAvailablePatch(root, patches);
            }
        }
        catch
        {
            return [];
        }

        return patches;
    }

    private static void AddPatch(JsonElement item, List<InstalledPatchSnapshot> patches)
    {
        if (item.ValueKind != JsonValueKind.Object)
        {
            return;
        }

        var kb = ReadString(item, "kb");
        if (string.IsNullOrWhiteSpace(kb))
        {
            kb = ReadString(item, "hotfixid");
        }

        if (string.IsNullOrWhiteSpace(kb))
        {
            return;
        }

        kb = kb.Trim().ToUpperInvariant();
        if (!kb.StartsWith("KB", StringComparison.OrdinalIgnoreCase))
        {
            kb = "KB" + kb;
        }

        patches.Add(new InstalledPatchSnapshot
        {
            Kb = kb,
            Title = ReadString(item, "title") ?? ReadString(item, "description") ?? string.Empty,
            InstalledAt = ReadString(item, "installed_at") ?? ReadString(item, "installedon") ?? string.Empty
        });
    }

    private static void AddAvailablePatch(JsonElement item, List<AvailablePatchSnapshot> patches)
    {
        if (item.ValueKind != JsonValueKind.Object)
        {
            return;
        }

        var updateId = ReadString(item, "update_id")
            ?? ReadString(item, "kb")
            ?? ReadString(item, "id")
            ?? string.Empty;
        var title = ReadString(item, "title") ?? ReadString(item, "description") ?? string.Empty;

        updateId = NormalizeUpdateIdentifier(updateId);
        title = title.Trim();

        if (string.IsNullOrWhiteSpace(updateId) && string.IsNullOrWhiteSpace(title))
        {
            return;
        }

        if (string.IsNullOrWhiteSpace(updateId))
        {
            updateId = title;
        }

        patches.Add(new AvailablePatchSnapshot
        {
            UpdateId = updateId,
            Title = title
        });
    }

    private static string NormalizeUpdateIdentifier(string value)
    {
        var normalized = value.Trim();
        if (string.IsNullOrWhiteSpace(normalized))
        {
            return string.Empty;
        }

        if (uint.TryParse(normalized, out _))
        {
            return "KB" + normalized;
        }

        if (normalized.StartsWith("KB", StringComparison.OrdinalIgnoreCase))
        {
            return normalized.ToUpperInvariant();
        }

        return normalized;
    }

    private static string? ReadString(JsonElement item, string propertyName)
    {
        foreach (var property in item.EnumerateObject())
        {
            if (!string.Equals(property.Name, propertyName, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            if (property.Value.ValueKind == JsonValueKind.String)
            {
                return property.Value.GetString();
            }

            return property.Value.ToString();
        }

        return null;
    }

    private static string? ReadLinuxKernelVersion()
    {
        try
        {
            if (File.Exists("/proc/sys/kernel/osrelease"))
            {
                return File.ReadAllText("/proc/sys/kernel/osrelease").Trim();
            }

            return RuntimeInformation.OSDescription;
        }
        catch
        {
            return null;
        }
    }

    private static Dictionary<string, string> ParseOsRelease()
    {
        var data = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

        try
        {
            if (!File.Exists("/etc/os-release"))
            {
                return data;
            }

            foreach (var line in File.ReadAllLines("/etc/os-release"))
            {
                if (string.IsNullOrWhiteSpace(line) || line.StartsWith('#'))
                {
                    continue;
                }

                var delimiter = line.IndexOf('=');
                if (delimiter <= 0)
                {
                    continue;
                }

                var key = line[..delimiter].Trim();
                var value = line[(delimiter + 1)..].Trim().Trim('"');
                if (!string.IsNullOrWhiteSpace(key))
                {
                    data[key] = value;
                }
            }
        }
        catch
        {
            return data;
        }

        return data;
    }

    private async Task<string?> ReadMacSwVersValueAsync(string argument, CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsMacOS())
        {
            return null;
        }

        var result = await RunProcessAsync(
            "sw_vers",
            [argument],
            TimeSpan.FromSeconds(10),
            cancellationToken);

        if (result.ExitCode != 0)
        {
            return null;
        }

        var value = result.StandardOutput.Trim();
        return string.IsNullOrWhiteSpace(value) ? null : value;
    }

    private async Task<MacSoftwareUpdateSnapshot> CollectMacAvailableUpdatesAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsMacOS())
        {
            return new MacSoftwareUpdateSnapshot(false, []);
        }

        if (!File.Exists("/usr/sbin/softwareupdate") && !File.Exists("/usr/bin/softwareupdate"))
        {
            return new MacSoftwareUpdateSnapshot(false, []);
        }

        var result = await RunProcessAsync(
            "softwareupdate",
            ["--list"],
            TimeSpan.FromSeconds(45),
            cancellationToken);

        if (result.ExitCode != 0 && string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            _logger.LogDebug(
                "macOS softwareupdate inventory query failed with exit code {ExitCode}: {Error}",
                result.ExitCode,
                result.StandardError);
            return new MacSoftwareUpdateSnapshot(false, []);
        }

        var labels = ParseMacSoftwareUpdateLabels(result.StandardOutput);
        var output = result.StandardOutput.ToLowerInvariant();
        var hasNoUpdatesMarker = output.Contains("no new software available", StringComparison.Ordinal);
        var hasUpdatesMarker = output.Contains("software update found the following", StringComparison.Ordinal)
            || output.Contains("new or updated software", StringComparison.Ordinal);

        var available = !hasNoUpdatesMarker && (labels.Count > 0 || hasUpdatesMarker);
        return new MacSoftwareUpdateSnapshot(available, labels);
    }

    private static List<string> ParseMacSoftwareUpdateLabels(string output)
    {
        var labels = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

        foreach (var rawLine in output.Split('\n'))
        {
            var line = rawLine.Trim();
            if (line.Length == 0)
            {
                continue;
            }

            var labelIndex = line.IndexOf("Label:", StringComparison.OrdinalIgnoreCase);
            if (labelIndex >= 0)
            {
                var label = line[(labelIndex + "Label:".Length)..].Trim();
                if (label.Length > 0)
                {
                    labels.Add(label);
                }
                continue;
            }

            if (!line.StartsWith("*", StringComparison.Ordinal) && !line.StartsWith("-", StringComparison.Ordinal))
            {
                continue;
            }

            var candidate = line.TrimStart('*', '-').Trim();
            if (candidate.Length == 0)
            {
                continue;
            }

            if (candidate.Contains("software update", StringComparison.OrdinalIgnoreCase)
                || candidate.Contains("finding available software", StringComparison.OrdinalIgnoreCase)
                || candidate.Contains("title:", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            var commaIndex = candidate.IndexOf(',');
            if (commaIndex > 0)
            {
                candidate = candidate[..commaIndex].Trim();
            }

            if (candidate.Length > 0)
            {
                labels.Add(candidate);
            }
        }

        return labels.ToList();
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

    private readonly record struct MacSoftwareUpdateSnapshot(
        bool SoftwareUpdateAvailable,
        List<string> AvailableUpdateLabels);
}
