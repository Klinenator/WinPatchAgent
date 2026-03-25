using System.Runtime.InteropServices;

namespace PatchAgent.Service.Models;

public sealed class InventorySnapshot
{
    public DateTimeOffset CollectedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    public string Hostname { get; set; } = Environment.MachineName;

    public string DomainOrWorkgroup { get; set; } = Environment.UserDomainName;

    public string PrimaryMacAddress { get; set; } = string.Empty;

    public string OsDescription { get; set; } = RuntimeInformation.OSDescription;

    public string OsArchitecture { get; set; } = RuntimeInformation.OSArchitecture.ToString();

    public string ProcessArchitecture { get; set; } = RuntimeInformation.ProcessArchitecture.ToString();

    public bool PendingReboot { get; set; }

    public long? FreeDiskMb { get; set; }

    public string? LinuxDistroId { get; set; }

    public string? LinuxDistroVersionId { get; set; }

    public string? LinuxKernelVersion { get; set; }

    public bool AptAvailable { get; set; }

    public bool LinuxPackageUpdatesAvailable { get; set; }

    public List<string> LinuxAvailablePackages { get; set; } = [];

    public string? MacOsProductVersion { get; set; }

    public string? MacOsBuildVersion { get; set; }

    public bool MacSoftwareUpdateAvailable { get; set; }

    public List<string> MacAvailableUpdateLabels { get; set; } = [];

    public int MacAvailableUpdatesCount { get; set; }

    public List<InstalledPatchSnapshot> InstalledWindowsPatches { get; set; } = [];

    public List<AvailablePatchSnapshot> AvailableWindowsPatches { get; set; } = [];
}

public sealed class InstalledPatchSnapshot
{
    public string Kb { get; set; } = string.Empty;

    public string Title { get; set; } = string.Empty;

    public string InstalledAt { get; set; } = string.Empty;
}

public sealed class AvailablePatchSnapshot
{
    public string UpdateId { get; set; } = string.Empty;

    public string Title { get; set; } = string.Empty;
}
