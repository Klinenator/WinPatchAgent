using Microsoft.Extensions.Logging;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Models;
using System.Diagnostics;
using System.Net.NetworkInformation;
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
            PrimaryMacAddress = ReadPrimaryMacAddress(),
            LoggedInUser = await ReadLoggedInUserAsync(cancellationToken),
            FreeDiskMb = freeDiskMb
        };

        if (OperatingSystem.IsWindows())
        {
            snapshot.PendingReboot = IsWindowsRebootPending();
            snapshot.InstalledWindowsPatches = await CollectInstalledWindowsPatchesAsync(cancellationToken);
            snapshot.AvailableWindowsPatches = await CollectAvailableWindowsPatchesAsync(cancellationToken);
            snapshot.WindowsSecurity = await CollectWindowsSecuritySnapshotAsync(cancellationToken);
            snapshot.Applications = await CollectInstalledWindowsApplicationsAsync(cancellationToken);
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

            if (snapshot.AptAvailable)
            {
                var linuxUpdateSnapshot = await CollectLinuxAvailableAptUpdatesAsync(cancellationToken);
                snapshot.LinuxPackageUpdatesAvailable = linuxUpdateSnapshot.PackageUpdatesAvailable;
                snapshot.LinuxAvailablePackages = linuxUpdateSnapshot.AvailablePackages;
                snapshot.LinuxAvailablePackageDetails = linuxUpdateSnapshot.AvailablePackageDetails;
            }

            snapshot.Applications = await CollectInstalledLinuxApplicationsAsync(cancellationToken);
        }
        else if (OperatingSystem.IsMacOS())
        {
            snapshot.PendingReboot = false;
            snapshot.MacOsProductVersion = await ReadMacSwVersValueAsync("-productVersion", cancellationToken);
            snapshot.MacOsBuildVersion = await ReadMacSwVersValueAsync("-buildVersion", cancellationToken);

            var macOsUpdateSnapshot = await CollectMacAvailableUpdatesAsync(cancellationToken);
            snapshot.MacSoftwareUpdateAvailable = macOsUpdateSnapshot.SoftwareUpdateAvailable;
            snapshot.MacAvailableUpdateLabels = macOsUpdateSnapshot.AvailableUpdateLabels;
            snapshot.MacAvailableUpdatesCount = macOsUpdateSnapshot.AvailableUpdateLabels.Count > 0
                ? macOsUpdateSnapshot.AvailableUpdateLabels.Count
                : (macOsUpdateSnapshot.SoftwareUpdateAvailable ? 1 : 0);
            snapshot.Applications = await CollectInstalledMacApplicationsAsync(cancellationToken);
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

    private async Task<WindowsSecuritySnapshot> CollectWindowsSecuritySnapshotAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
        {
            return new WindowsSecuritySnapshot();
        }

        var script = @"
$ErrorActionPreference = 'SilentlyContinue'

$result = [ordered]@{
  edition = ''
  defender_service_present = $false
  defender_service_state = 'not_found'
  defender_realtime_enabled = $null
  defender_antivirus_enabled = $null
  defender_amservice_enabled = $null
  defender_tamper_protected = $null
  defender_running_mode = 'unknown'
  firewall_domain_enabled = $null
  firewall_private_enabled = $null
  firewall_public_enabled = $null
  removable_storage_deny_all = $false
  bitlocker_support = 'unknown'
  bitlocker_os_volume_protection = 'unknown'
}

try {
  $product = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -Name ProductName -ErrorAction SilentlyContinue
  if ($null -ne $product -and $null -ne $product.ProductName) {
    $result.edition = [string]$product.ProductName
    if ($result.edition -match 'Home') {
      $result.bitlocker_support = 'not_supported'
      $result.bitlocker_os_volume_protection = 'not_supported'
    }
  }
} catch {
}

try {
  $svc = Get-Service -Name 'WinDefend' -ErrorAction SilentlyContinue
  if ($null -ne $svc) {
    $result.defender_service_present = $true
    $state = [string]$svc.Status
    if ($state -match 'Running') {
      $result.defender_service_state = 'running'
    } elseif ($state -match 'Stopped') {
      $result.defender_service_state = 'stopped'
    } else {
      $result.defender_service_state = 'unknown'
    }
  }
} catch {
}

try {
  if (Get-Command -Name Get-MpComputerStatus -ErrorAction SilentlyContinue) {
    $mp = Get-MpComputerStatus -ErrorAction SilentlyContinue
    if ($null -ne $mp -and $null -ne $mp.RealTimeProtectionEnabled) {
      $result.defender_realtime_enabled = [bool]$mp.RealTimeProtectionEnabled
    }
    if ($null -ne $mp -and $null -ne $mp.AntivirusEnabled) {
      $result.defender_antivirus_enabled = [bool]$mp.AntivirusEnabled
    }
    if ($null -ne $mp -and $null -ne $mp.AMServiceEnabled) {
      $result.defender_amservice_enabled = [bool]$mp.AMServiceEnabled
    }
    if ($null -ne $mp -and $null -ne $mp.IsTamperProtected) {
      $result.defender_tamper_protected = [bool]$mp.IsTamperProtected
    }
    if ($null -ne $mp -and $null -ne $mp.AMRunningMode) {
      $runningMode = [string]$mp.AMRunningMode
      if (-not [string]::IsNullOrWhiteSpace($runningMode)) {
        $result.defender_running_mode = $runningMode
      }
    }
  }
} catch {
}

try {
  if (Get-Command -Name Get-NetFirewallProfile -ErrorAction SilentlyContinue) {
    $profiles = Get-NetFirewallProfile -ErrorAction SilentlyContinue
    foreach ($profile in $profiles) {
      $name = [string]$profile.Name
      $enabled = [bool]$profile.Enabled
      if ($name -eq 'Domain') {
        $result.firewall_domain_enabled = $enabled
      } elseif ($name -eq 'Private') {
        $result.firewall_private_enabled = $enabled
      } elseif ($name -eq 'Public') {
        $result.firewall_public_enabled = $enabled
      }
    }
  }
} catch {
}

try {
  $policy = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices' -Name 'Deny_All' -ErrorAction SilentlyContinue
  if ($null -ne $policy -and $null -ne $policy.Deny_All) {
    $result.removable_storage_deny_all = ([int]$policy.Deny_All) -eq 1
  }
} catch {
}

$systemDrive = $env:SystemDrive
if ([string]::IsNullOrWhiteSpace($systemDrive)) {
  $systemDrive = 'C:'
}

try {
  if ((Get-Command -Name Get-BitLockerVolume -ErrorAction SilentlyContinue) -and $result.bitlocker_support -ne 'not_supported') {
    $volume = Get-BitLockerVolume -MountPoint $systemDrive -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -ne $volume) {
      $result.bitlocker_support = 'supported'
      $protection = [string]$volume.ProtectionStatus
      if ($protection -eq 'On' -or $protection -eq '1') {
        $result.bitlocker_os_volume_protection = 'on'
      } elseif ($protection -eq 'Off' -or $protection -eq '0') {
        if ([string]$volume.VolumeStatus -eq 'FullyDecrypted') {
          $result.bitlocker_os_volume_protection = 'off'
        } else {
          $result.bitlocker_os_volume_protection = 'suspended'
        }
      }
    }
  }
} catch {
}

try {
  if ($result.bitlocker_os_volume_protection -eq 'unknown' -and $result.bitlocker_support -ne 'not_supported') {
    $manageBde = Get-Command -Name 'manage-bde.exe' -ErrorAction SilentlyContinue
    if ($null -ne $manageBde) {
      $statusOutput = & $manageBde.Source -status $systemDrive 2>$null | Out-String
      if (-not [string]::IsNullOrWhiteSpace($statusOutput)) {
        $result.bitlocker_support = 'supported'
        if ($statusOutput -match 'Protection Status:\s*Protection On') {
          $result.bitlocker_os_volume_protection = 'on'
        } elseif ($statusOutput -match 'Protection Status:\s*Protection Off') {
          $result.bitlocker_os_volume_protection = 'off'
        } elseif ($statusOutput -match 'Protection Status:\s*Protection Suspended') {
          $result.bitlocker_os_volume_protection = 'suspended'
        }
      }
    } elseif ($result.bitlocker_support -eq 'unknown') {
      $result.bitlocker_support = 'not_supported'
      $result.bitlocker_os_volume_protection = 'not_supported'
    }
  }
} catch {
}

$result | ConvertTo-Json -Depth 4 -Compress
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
                "Windows security inventory query failed with exit code {ExitCode}: {Error}",
                result.ExitCode,
                result.StandardError);
            return new WindowsSecuritySnapshot();
        }

        return ParseWindowsSecurityInventoryJson(result.StandardOutput);
    }

    private async Task<List<InstalledApplicationSnapshot>> CollectInstalledWindowsApplicationsAsync(
        CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
        {
            return [];
        }

        var script = @"
$ErrorActionPreference = 'SilentlyContinue'
$items = @()
$paths = @(
  'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
  'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
)

foreach ($path in $paths) {
  try {
    $entries = Get-ItemProperty -Path $path -ErrorAction SilentlyContinue
    foreach ($entry in $entries) {
      $name = [string]($entry.DisplayName)
      if ([string]::IsNullOrWhiteSpace($name)) {
        continue
      }

      $installDate = ''
      if ($entry.InstallDate) {
        $candidate = [string]$entry.InstallDate
        try {
          if ($candidate -match '^\d{8}$') {
            $installDate = [datetime]::ParseExact($candidate, 'yyyyMMdd', $null).ToString('o')
          } else {
            $installDate = (Get-Date $candidate).ToString('o')
          }
        } catch {
          $installDate = $candidate
        }
      }

      $items += [PSCustomObject]@{
        name = $name.Trim()
        version = [string]($entry.DisplayVersion)
        publisher = [string]($entry.Publisher)
        source = 'windows-registry'
        installed_at = $installDate
      }
    }
  } catch {
  }
}

$items |
  Sort-Object -Property name, version -Unique |
  Select-Object -First 2500 |
  ConvertTo-Json -Depth 5 -Compress
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
                "Windows application inventory query failed with exit code {ExitCode}: {Error}",
                result.ExitCode,
                result.StandardError);
            return [];
        }

        return ParseInstalledApplicationsJson(result.StandardOutput, "windows-registry");
    }

    private async Task<List<InstalledApplicationSnapshot>> CollectInstalledLinuxApplicationsAsync(
        CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsLinux())
        {
            return [];
        }

        if (File.Exists("/usr/bin/dpkg-query"))
        {
            var dpkgResult = await RunProcessAsync(
                "/usr/bin/dpkg-query",
                ["-W", "-f=${binary:Package}\t${Version}\n"],
                TimeSpan.FromSeconds(60),
                cancellationToken);

            if (dpkgResult.ExitCode == 0 && !string.IsNullOrWhiteSpace(dpkgResult.StandardOutput))
            {
                return ParseLinuxPackageInventory(dpkgResult.StandardOutput, "dpkg");
            }
        }

        if (File.Exists("/usr/bin/rpm"))
        {
            var rpmResult = await RunProcessAsync(
                "/usr/bin/rpm",
                ["-qa", "--qf", "%{NAME}\t%{VERSION}-%{RELEASE}\n"],
                TimeSpan.FromSeconds(60),
                cancellationToken);

            if (rpmResult.ExitCode == 0 && !string.IsNullOrWhiteSpace(rpmResult.StandardOutput))
            {
                return ParseLinuxPackageInventory(rpmResult.StandardOutput, "rpm");
            }
        }

        return [];
    }

    private async Task<List<InstalledApplicationSnapshot>> CollectInstalledMacApplicationsAsync(
        CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsMacOS())
        {
            return [];
        }

        if (!File.Exists("/opt/homebrew/bin/brew")
            && !File.Exists("/usr/local/bin/brew")
            && !File.Exists("/usr/bin/brew"))
        {
            return [];
        }

        var applications = new List<InstalledApplicationSnapshot>();
        applications.AddRange(await CollectMacBrewApplicationsAsync(["list", "--formula", "--versions"], "brew-formula", cancellationToken));
        applications.AddRange(await CollectMacBrewApplicationsAsync(["list", "--cask", "--versions"], "brew-cask", cancellationToken));

        return NormalizeApplications(applications);
    }

    private async Task<List<InstalledApplicationSnapshot>> CollectMacBrewApplicationsAsync(
        IReadOnlyList<string> args,
        string source,
        CancellationToken cancellationToken)
    {
        var result = await RunProcessAsync(
            "brew",
            args,
            TimeSpan.FromSeconds(45),
            cancellationToken);

        if (result.ExitCode != 0 || string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            return [];
        }

        var applications = new List<InstalledApplicationSnapshot>();
        foreach (var rawLine in result.StandardOutput.Split('\n'))
        {
            var line = rawLine.Trim();
            if (line.Length == 0)
            {
                continue;
            }

            var parts = line.Split(' ', StringSplitOptions.RemoveEmptyEntries);
            if (parts.Length == 0)
            {
                continue;
            }

            var name = parts[0].Trim();
            if (name.Length == 0)
            {
                continue;
            }

            var version = parts.Length > 1
                ? string.Join(' ', parts.Skip(1)).Trim()
                : string.Empty;

            applications.Add(new InstalledApplicationSnapshot
            {
                Name = name,
                Version = version,
                Source = source
            });
        }

        return NormalizeApplications(applications);
    }

    private static List<InstalledApplicationSnapshot> ParseLinuxPackageInventory(string output, string source)
    {
        var applications = new List<InstalledApplicationSnapshot>();

        foreach (var rawLine in output.Split('\n'))
        {
            var line = rawLine.Trim();
            if (line.Length == 0)
            {
                continue;
            }

            var parts = line.Split('\t', 2, StringSplitOptions.TrimEntries);
            if (parts.Length == 0 || string.IsNullOrWhiteSpace(parts[0]))
            {
                continue;
            }

            applications.Add(new InstalledApplicationSnapshot
            {
                Name = parts[0].Trim(),
                Version = parts.Length > 1 ? parts[1].Trim() : string.Empty,
                Source = source
            });
        }

        return NormalizeApplications(applications);
    }

    private static List<InstalledApplicationSnapshot> ParseInstalledApplicationsJson(string rawJson, string defaultSource)
    {
        var applications = new List<InstalledApplicationSnapshot>();

        try
        {
            using var document = JsonDocument.Parse(rawJson);
            var root = document.RootElement;
            if (root.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in root.EnumerateArray())
                {
                    AddInstalledApplicationFromJson(item, applications, defaultSource);
                }
            }
            else if (root.ValueKind == JsonValueKind.Object)
            {
                AddInstalledApplicationFromJson(root, applications, defaultSource);
            }
        }
        catch
        {
            return [];
        }

        return NormalizeApplications(applications);
    }

    private static void AddInstalledApplicationFromJson(
        JsonElement item,
        List<InstalledApplicationSnapshot> applications,
        string defaultSource)
    {
        if (item.ValueKind != JsonValueKind.Object)
        {
            return;
        }

        var name = (ReadString(item, "name") ?? ReadString(item, "display_name") ?? string.Empty).Trim();
        if (name.Length == 0)
        {
            return;
        }

        var version = (ReadString(item, "version") ?? ReadString(item, "display_version") ?? string.Empty).Trim();
        var publisher = (ReadString(item, "publisher") ?? string.Empty).Trim();
        var source = (ReadString(item, "source") ?? defaultSource ?? string.Empty).Trim();
        var installedAt = (ReadString(item, "installed_at") ?? ReadString(item, "install_date") ?? string.Empty).Trim();

        applications.Add(new InstalledApplicationSnapshot
        {
            Name = name,
            Version = version,
            Publisher = publisher,
            Source = source,
            InstalledAt = installedAt
        });
    }

    private static List<InstalledApplicationSnapshot> NormalizeApplications(
        IEnumerable<InstalledApplicationSnapshot> applications)
    {
        var normalized = new Dictionary<string, InstalledApplicationSnapshot>(StringComparer.OrdinalIgnoreCase);

        foreach (var application in applications)
        {
            var name = (application.Name ?? string.Empty).Trim();
            if (name.Length == 0)
            {
                continue;
            }

            var version = (application.Version ?? string.Empty).Trim();
            var source = (application.Source ?? string.Empty).Trim();
            var key = (name + "|" + version + "|" + source).ToLowerInvariant();

            if (!normalized.TryGetValue(key, out var existing))
            {
                normalized[key] = new InstalledApplicationSnapshot
                {
                    Name = name,
                    Version = version,
                    Publisher = (application.Publisher ?? string.Empty).Trim(),
                    Source = source,
                    InstalledAt = (application.InstalledAt ?? string.Empty).Trim()
                };
                continue;
            }

            if (existing.Publisher.Length == 0 && !string.IsNullOrWhiteSpace(application.Publisher))
            {
                existing.Publisher = application.Publisher.Trim();
            }

            if (existing.InstalledAt.Length == 0 && !string.IsNullOrWhiteSpace(application.InstalledAt))
            {
                existing.InstalledAt = application.InstalledAt.Trim();
            }
        }

        return normalized
            .Values
            .OrderBy(static application => application.Name, StringComparer.OrdinalIgnoreCase)
            .ThenBy(static application => application.Version, StringComparer.OrdinalIgnoreCase)
            .Take(2500)
            .ToList();
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

    private static WindowsSecuritySnapshot ParseWindowsSecurityInventoryJson(string rawJson)
    {
        var snapshot = new WindowsSecuritySnapshot();

        try
        {
            using var document = JsonDocument.Parse(rawJson);
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
            {
                return snapshot;
            }

            snapshot.Edition = (ReadString(root, "edition") ?? string.Empty).Trim();
            snapshot.DefenderServicePresent = ReadBoolean(root, "defender_service_present") ?? false;
            snapshot.DefenderServiceState = NormalizeDefenderServiceState(ReadString(root, "defender_service_state"));
            snapshot.DefenderRealtimeEnabled = ReadBoolean(root, "defender_realtime_enabled");
            snapshot.DefenderAntivirusEnabled = ReadBoolean(root, "defender_antivirus_enabled");
            snapshot.DefenderAmServiceEnabled = ReadBoolean(root, "defender_amservice_enabled");
            snapshot.DefenderTamperProtected = ReadBoolean(root, "defender_tamper_protected");
            snapshot.DefenderRunningMode = NormalizeDefenderRunningMode(ReadString(root, "defender_running_mode"));
            snapshot.FirewallDomainEnabled = ReadBoolean(root, "firewall_domain_enabled");
            snapshot.FirewallPrivateEnabled = ReadBoolean(root, "firewall_private_enabled");
            snapshot.FirewallPublicEnabled = ReadBoolean(root, "firewall_public_enabled");
            snapshot.RemovableStorageDenyAll = ReadBoolean(root, "removable_storage_deny_all") ?? false;
            snapshot.BitlockerSupport = NormalizeBitlockerSupport(ReadString(root, "bitlocker_support"));
            snapshot.BitlockerOsVolumeProtection = NormalizeBitlockerProtection(ReadString(root, "bitlocker_os_volume_protection"));
        }
        catch
        {
            return new WindowsSecuritySnapshot();
        }

        return snapshot;
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

    private static bool? ReadBoolean(JsonElement item, string propertyName)
    {
        foreach (var property in item.EnumerateObject())
        {
            if (!string.Equals(property.Name, propertyName, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            return property.Value.ValueKind switch
            {
                JsonValueKind.True => true,
                JsonValueKind.False => false,
                JsonValueKind.String => ParseBoolean(property.Value.GetString()),
                JsonValueKind.Number => property.Value.TryGetInt32(out var intValue) ? intValue != 0 : null,
                _ => null
            };
        }

        return null;
    }

    private static bool? ParseBoolean(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return null;
        }

        var normalized = value.Trim().ToLowerInvariant();
        return normalized switch
        {
            "1" or "true" or "yes" or "on" => true,
            "0" or "false" or "no" or "off" => false,
            _ => null
        };
    }

    private static string NormalizeDefenderServiceState(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
        return normalized switch
        {
            "running" => "running",
            "stopped" => "stopped",
            "not_found" => "not_found",
            _ => "unknown"
        };
    }

    private static string NormalizeDefenderRunningMode(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
        if (string.IsNullOrEmpty(normalized))
        {
            return "unknown";
        }

        normalized = normalized.Replace(' ', '_').Replace('-', '_');

        if (normalized.Contains("passive", StringComparison.Ordinal))
        {
            return "passive";
        }

        if (normalized.Contains("block", StringComparison.Ordinal))
        {
            return "edr_block_mode";
        }

        if (normalized.Contains("normal", StringComparison.Ordinal) || normalized.Contains("active", StringComparison.Ordinal))
        {
            return "normal";
        }

        return "unknown";
    }

    private static string NormalizeBitlockerSupport(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
        return normalized switch
        {
            "supported" => "supported",
            "not_supported" => "not_supported",
            _ => "unknown"
        };
    }

    private static string NormalizeBitlockerProtection(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
        return normalized switch
        {
            "on" => "on",
            "off" => "off",
            "suspended" => "suspended",
            "not_supported" => "not_supported",
            _ => "unknown"
        };
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

    private static string ReadPrimaryMacAddress()
    {
        try
        {
            var candidates = NetworkInterface.GetAllNetworkInterfaces()
                .Where(static nic =>
                    nic.OperationalStatus == OperationalStatus.Up
                    && nic.NetworkInterfaceType != NetworkInterfaceType.Loopback
                    && nic.NetworkInterfaceType != NetworkInterfaceType.Tunnel)
                .Select(static nic => nic.GetPhysicalAddress())
                .Where(static address => address is not null && address.GetAddressBytes().Length >= 6)
                .Select(static address => address!.ToString().Trim().ToUpperInvariant())
                .Where(static mac => mac.Length >= 12 && mac != "000000000000")
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .OrderBy(static mac => mac, StringComparer.OrdinalIgnoreCase)
                .ToList();

            return candidates.Count > 0 ? candidates[0] : string.Empty;
        }
        catch
        {
            return string.Empty;
        }
    }

    private static async Task<string> ReadLoggedInUserAsync(CancellationToken cancellationToken)
    {
        try
        {
            if (OperatingSystem.IsWindows())
            {
                return await ReadWindowsLoggedInUserAsync(cancellationToken);
            }

            if (OperatingSystem.IsLinux())
            {
                return await ReadLinuxLoggedInUserAsync(cancellationToken);
            }

            if (OperatingSystem.IsMacOS())
            {
                return await ReadMacLoggedInUserAsync(cancellationToken);
            }
        }
        catch
        {
        }

        return string.Empty;
    }

    private static async Task<string> ReadWindowsLoggedInUserAsync(CancellationToken cancellationToken)
    {
        const string script = @"
$ErrorActionPreference = 'SilentlyContinue'
$user = ''
try {
  $cs = Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction SilentlyContinue
  if ($null -ne $cs -and $null -ne $cs.UserName) {
    $user = [string]$cs.UserName
  }
} catch {
}

if (-not [string]::IsNullOrWhiteSpace($user)) {
  Write-Output $user
}
";

        var result = await RunProcessAsync(
            "powershell",
            ["-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script],
            TimeSpan.FromSeconds(5),
            cancellationToken);

        if (result.ExitCode != 0 && string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            return string.Empty;
        }

        return NormalizeLoggedInUserLine(result.StandardOutput);
    }

    private static async Task<string> ReadLinuxLoggedInUserAsync(CancellationToken cancellationToken)
    {
        var whoExecutable = File.Exists("/usr/bin/who") ? "/usr/bin/who" : "who";
        var whoResult = await RunProcessAsync(
            whoExecutable,
            [],
            TimeSpan.FromSeconds(3),
            cancellationToken);

        if (whoResult.ExitCode == 0)
        {
            foreach (var rawLine in whoResult.StandardOutput.Split('\n'))
            {
                var line = rawLine.Trim();
                if (line.Length == 0)
                {
                    continue;
                }

                var parts = line.Split(' ', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
                if (parts.Length == 0)
                {
                    continue;
                }

                var candidate = parts[0].Trim();
                if (candidate.Length == 0 || string.Equals(candidate, "reboot", StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }

                return candidate;
            }
        }

        return string.Empty;
    }

    private static async Task<string> ReadMacLoggedInUserAsync(CancellationToken cancellationToken)
    {
        var statResult = await RunProcessAsync(
            "/usr/bin/stat",
            ["-f%Su", "/dev/console"],
            TimeSpan.FromSeconds(3),
            cancellationToken);

        if (statResult.ExitCode != 0)
        {
            return string.Empty;
        }

        var value = NormalizeLoggedInUserLine(statResult.StandardOutput);
        if (string.Equals(value, "root", StringComparison.OrdinalIgnoreCase)
            || string.Equals(value, "loginwindow", StringComparison.OrdinalIgnoreCase))
        {
            return string.Empty;
        }

        return value;
    }

    private static string NormalizeLoggedInUserLine(string rawOutput)
    {
        if (string.IsNullOrWhiteSpace(rawOutput))
        {
            return string.Empty;
        }

        var firstLine = rawOutput
            .Split('\n')
            .Select(static line => line.Trim())
            .FirstOrDefault(static line => !string.IsNullOrWhiteSpace(line))
            ?? string.Empty;

        if (firstLine.Length > 120)
        {
            firstLine = firstLine[..120];
        }

        return firstLine;
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

    private async Task<LinuxAptUpdateSnapshot> CollectLinuxAvailableAptUpdatesAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsLinux())
        {
            return new LinuxAptUpdateSnapshot(false, [], []);
        }

        var aptExecutable = File.Exists("/usr/bin/apt") ? "/usr/bin/apt" : "apt";
        var result = await RunProcessAsync(
            aptExecutable,
            ["list", "--upgradable"],
            TimeSpan.FromSeconds(30),
            cancellationToken);

        if (result.ExitCode != 0 && string.IsNullOrWhiteSpace(result.StandardOutput))
        {
            var aptGetExecutable = File.Exists("/usr/bin/apt-get") ? "/usr/bin/apt-get" : "apt-get";
            var fallback = await RunProcessAsync(
                aptGetExecutable,
                ["-s", "upgrade"],
                TimeSpan.FromSeconds(30),
                cancellationToken);

            if (fallback.ExitCode != 0)
            {
                _logger.LogDebug(
                    "Linux apt inventory query failed with exit code {ExitCode}: {Error}",
                    result.ExitCode,
                    result.StandardError);
                return new LinuxAptUpdateSnapshot(false, [], []);
            }

            var fallbackCount = ParseAptGetSimulationUpgradeCount(fallback.StandardOutput);
            if (fallbackCount <= 0)
            {
                return new LinuxAptUpdateSnapshot(false, [], []);
            }

            var fallbackDetails = BuildFallbackPackagePlaceholderDetails(fallbackCount);
            return new LinuxAptUpdateSnapshot(
                true,
                fallbackDetails.Select(static package => package.Name).ToList(),
                fallbackDetails);
        }

        var availablePackageDetails = ParseAptUpgradeablePackageDetails(result.StandardOutput);
        var availablePackages = availablePackageDetails
            .Select(static package => package.Name)
            .Where(static name => !string.IsNullOrWhiteSpace(name))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .OrderBy(static name => name, StringComparer.OrdinalIgnoreCase)
            .ToList();

        return new LinuxAptUpdateSnapshot(
            availablePackages.Count > 0,
            availablePackages,
            availablePackageDetails);
    }

    private static List<LinuxAvailablePackageSnapshot> ParseAptUpgradeablePackageDetails(string output)
    {
        var packages = new Dictionary<string, LinuxAvailablePackageSnapshot>(StringComparer.OrdinalIgnoreCase);

        foreach (var rawLine in output.Split('\n'))
        {
            var line = rawLine.Trim();
            if (line.Length == 0)
            {
                continue;
            }

            if (line.StartsWith("Listing", StringComparison.OrdinalIgnoreCase)
                || line.StartsWith("WARNING", StringComparison.OrdinalIgnoreCase)
                || line.StartsWith("N:", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            var parsed = ParseAptUpgradeablePackageLine(line);
            if (parsed is null)
            {
                continue;
            }

            if (packages.TryGetValue(parsed.Name, out var existing))
            {
                if (string.IsNullOrWhiteSpace(existing.CurrentVersion) && !string.IsNullOrWhiteSpace(parsed.CurrentVersion))
                {
                    existing.CurrentVersion = parsed.CurrentVersion;
                }

                if (string.IsNullOrWhiteSpace(existing.CandidateVersion) && !string.IsNullOrWhiteSpace(parsed.CandidateVersion))
                {
                    existing.CandidateVersion = parsed.CandidateVersion;
                }

                if (string.IsNullOrWhiteSpace(existing.Architecture) && !string.IsNullOrWhiteSpace(parsed.Architecture))
                {
                    existing.Architecture = parsed.Architecture;
                }

                if (string.IsNullOrWhiteSpace(existing.Source) && !string.IsNullOrWhiteSpace(parsed.Source))
                {
                    existing.Source = parsed.Source;
                }

                if (string.IsNullOrWhiteSpace(existing.RawLine))
                {
                    existing.RawLine = parsed.RawLine;
                }

                packages[parsed.Name] = existing;
            }
            else
            {
                packages[parsed.Name] = parsed;
            }
        }

        return packages
            .Values
            .OrderBy(static package => package.Name, StringComparer.OrdinalIgnoreCase)
            .ToList();
    }

    private static LinuxAvailablePackageSnapshot? ParseAptUpgradeablePackageLine(string line)
    {
        var firstSpaceIndex = line.IndexOf(' ');
        if (firstSpaceIndex <= 0)
        {
            return null;
        }

        var packageToken = line[..firstSpaceIndex].Trim();
        var remainder = line[(firstSpaceIndex + 1)..].Trim();
        if (packageToken.Length == 0 || remainder.Length == 0)
        {
            return null;
        }

        var slashIndex = packageToken.IndexOf('/');
        var packageName = (slashIndex > 0 ? packageToken[..slashIndex] : packageToken).Trim();
        if (packageName.Length == 0)
        {
            return null;
        }

        var source = slashIndex > 0
            ? packageToken[(slashIndex + 1)..].Trim()
            : "apt";

        var candidateVersion = string.Empty;
        var architecture = string.Empty;
        var remainderTokens = remainder.Split(' ', StringSplitOptions.RemoveEmptyEntries);
        if (remainderTokens.Length > 0)
        {
            candidateVersion = remainderTokens[0].Trim();
        }

        if (remainderTokens.Length > 1)
        {
            architecture = remainderTokens[1].Trim();
        }

        var currentVersion = string.Empty;
        var currentVersionMatch = System.Text.RegularExpressions.Regex.Match(
            line,
            @"\[\s*upgradable from:\s*(?<version>[^\]]+)\]",
            System.Text.RegularExpressions.RegexOptions.IgnoreCase);
        if (currentVersionMatch.Success)
        {
            currentVersion = currentVersionMatch.Groups["version"].Value.Trim();
        }

        return new LinuxAvailablePackageSnapshot
        {
            Name = packageName,
            CurrentVersion = currentVersion,
            CandidateVersion = candidateVersion,
            Architecture = architecture,
            Source = source,
            RawLine = line
        };
    }

    private static int ParseAptGetSimulationUpgradeCount(string output)
    {
        foreach (var rawLine in output.Split('\n'))
        {
            var line = rawLine.Trim();
            if (line.Length == 0)
            {
                continue;
            }

            var match = System.Text.RegularExpressions.Regex.Match(
                line,
                @"^(?<count>\d+)\s+upgraded,\s+\d+\s+newly installed,\s+\d+\s+to remove",
                System.Text.RegularExpressions.RegexOptions.IgnoreCase);

            if (!match.Success)
            {
                continue;
            }

            var groupValue = match.Groups["count"].Value;
            if (int.TryParse(groupValue, out var parsed) && parsed >= 0)
            {
                return parsed;
            }
        }

        return 0;
    }

    private static List<LinuxAvailablePackageSnapshot> BuildFallbackPackagePlaceholderDetails(int count)
    {
        var result = new List<LinuxAvailablePackageSnapshot>(count);
        for (var index = 1; index <= count; index++)
        {
            var packageName = "upgrade-" + index.ToString(System.Globalization.CultureInfo.InvariantCulture);
            result.Add(new LinuxAvailablePackageSnapshot
            {
                Name = packageName,
                Source = "apt-simulated",
                RawLine = packageName
            });
        }

        return result;
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

    private readonly record struct LinuxAptUpdateSnapshot(
        bool PackageUpdatesAvailable,
        List<string> AvailablePackages,
        List<LinuxAvailablePackageSnapshot> AvailablePackageDetails);

    private readonly record struct MacSoftwareUpdateSnapshot(
        bool SoftwareUpdateAvailable,
        List<string> AvailableUpdateLabels);
}
