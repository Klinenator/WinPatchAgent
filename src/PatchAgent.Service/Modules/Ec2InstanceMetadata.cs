using System.Net.Http;
using System.Text.RegularExpressions;

namespace PatchAgent.Service.Modules;

/// <summary>
/// Reads this host's EC2 instance id from IMDSv2.
///
/// The backend joins agents to EC2 rows on this value. Without it the join has to
/// infer identity from the hostname, which does not work for Windows: a default
/// EC2 Windows hostname looks like "EC2AMAZ-I9O92Q1" and matches neither the
/// ip-a-b-c-d form the join parses nor the instance's Name tag. SMB-Storage
/// reported healthily for exactly that reason while showing as "not enrolled".
///
/// Any failure is non-fatal and yields an empty string: not being on EC2 is a
/// normal condition (most agents in this fleet are desktops), and the backend
/// still falls back to hostname matching.
/// </summary>
internal static class Ec2InstanceMetadata
{
    private const string ImdsBase = "http://169.254.169.254/latest";

    // Link-local; it answers in single-digit milliseconds or it is not there at
    // all. Kept short so a non-EC2 host is not stalled on every call.
    private static readonly TimeSpan Timeout = TimeSpan.FromSeconds(2);

    private static readonly Regex InstanceIdPattern =
        new("^i-[0-9a-f]{8,32}$", RegexOptions.Compiled | RegexOptions.CultureInvariant);

    private static readonly SemaphoreSlim Gate = new(1, 1);
    private static string? _cached;

    /// <summary>
    /// The instance id, or "" when this host is not EC2 or IMDS is unreachable.
    /// Resolved once per process: an instance id cannot change while the machine
    /// is running, so there is no reason to hit IMDS on every heartbeat.
    /// </summary>
    public static async Task<string> GetInstanceIdAsync(CancellationToken cancellationToken)
    {
        if (_cached is not null)
        {
            return _cached;
        }

        await Gate.WaitAsync(cancellationToken).ConfigureAwait(false);
        try
        {
            if (_cached is not null)
            {
                return _cached;
            }

            _cached = await FetchAsync(cancellationToken).ConfigureAwait(false);
            return _cached;
        }
        finally
        {
            Gate.Release();
        }
    }

    private static async Task<string> FetchAsync(CancellationToken cancellationToken)
    {
        try
        {
            using var http = new HttpClient { Timeout = Timeout };

            // IMDSv2 only. Hosts that still allow v1 answer v2 as well, and hosts
            // launched with HttpTokens=required answer nothing else.
            using var tokenRequest = new HttpRequestMessage(HttpMethod.Put, $"{ImdsBase}/api/token");
            tokenRequest.Headers.Add("X-aws-ec2-metadata-token-ttl-seconds", "60");

            using var tokenResponse = await http.SendAsync(tokenRequest, cancellationToken).ConfigureAwait(false);
            if (!tokenResponse.IsSuccessStatusCode)
            {
                return string.Empty;
            }

            var token = (await tokenResponse.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false)).Trim();
            if (string.IsNullOrWhiteSpace(token))
            {
                return string.Empty;
            }

            using var idRequest = new HttpRequestMessage(HttpMethod.Get, $"{ImdsBase}/meta-data/instance-id");
            idRequest.Headers.Add("X-aws-ec2-metadata-token", token);

            using var idResponse = await http.SendAsync(idRequest, cancellationToken).ConfigureAwait(false);
            if (!idResponse.IsSuccessStatusCode)
            {
                return string.Empty;
            }

            var value = (await idResponse.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false)).Trim();
            return InstanceIdPattern.IsMatch(value) ? value : string.Empty;
        }
        catch (Exception)
        {
            // Includes the timeout on non-EC2 hosts. Never surfaced: an agent that
            // cannot read IMDS must still register and heartbeat normally.
            return string.Empty;
        }
    }
}
