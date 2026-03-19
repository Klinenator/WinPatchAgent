using System.Runtime.InteropServices;

namespace PatchAgent.Service.Models;

public sealed class InventorySnapshot
{
    public DateTimeOffset CollectedAtUtc { get; set; } = DateTimeOffset.UtcNow;

    public string Hostname { get; set; } = Environment.MachineName;

    public string DomainOrWorkgroup { get; set; } = Environment.UserDomainName;

    public string OsDescription { get; set; } = RuntimeInformation.OSDescription;

    public string OsArchitecture { get; set; } = RuntimeInformation.OSArchitecture.ToString();

    public string ProcessArchitecture { get; set; } = RuntimeInformation.ProcessArchitecture.ToString();

    public bool PendingReboot { get; set; }

    public long? FreeDiskMb { get; set; }
}
