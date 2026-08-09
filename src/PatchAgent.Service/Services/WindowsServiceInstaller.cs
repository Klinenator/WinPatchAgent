using System.Diagnostics;
using System.Runtime.Versioning;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using PatchAgent.Service.Configuration;

namespace PatchAgent.Service.Services;

[SupportedOSPlatform("windows")]
public static class WindowsServiceInstaller
{
    private const string DefaultServiceName = "PatchAgentSvc";
    private const string DefaultInstallDirectoryName = "WinPatchAgent";
    private const string DefaultAgentChannel = "stable";

    public static async Task<int?> TryRunAsync(string[] args, CancellationToken cancellationToken = default)
    {
        if (args.Length == 0)
        {
            return null;
        }

        var command = args[0].Trim().ToLowerInvariant();
        if (command is not ("install" or "uninstall"))
        {
            return null;
        }

        if (!OperatingSystem.IsWindows())
        {
            Console.Error.WriteLine("The install and uninstall commands are only supported on Windows.");
            return 1;
        }

        try
        {
            EnsureAdministrator();

            var options = InstallerCommandOptions.Parse(args.Skip(1).ToArray());
            switch (command)
            {
                case "install":
                    await InstallAsync(options, cancellationToken).ConfigureAwait(false);
                    return 0;
                case "uninstall":
                    await UninstallAsync(options, cancellationToken).ConfigureAwait(false);
                    return 0;
                default:
                    return null;
            }
        }
        catch (Exception exception)
        {
            Console.Error.WriteLine($"Installer command failed: {exception.Message}");
            return 1;
        }
    }

    private static async Task InstallAsync(InstallerCommandOptions options, CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(options.BackendUrl))
        {
            throw new InvalidOperationException("The install command requires --backend-url.");
        }

        if (string.IsNullOrWhiteSpace(options.EnrollmentKey))
        {
            throw new InvalidOperationException("The install command requires --enrollment-key.");
        }

        var installDirectory = options.InstallDirectory;
        var serviceName = options.ServiceName;
        var sourceDirectory = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var destinationDirectory = installDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var executablePath = Path.Combine(destinationDirectory, "PatchAgent.Service.exe");

        Directory.CreateDirectory(destinationDirectory);
        Directory.CreateDirectory(options.StorageRoot);

        Console.WriteLine($"Installing service {serviceName} to {destinationDirectory}");
        await StopAndDeleteServiceIfPresentAsync(serviceName, cancellationToken).ConfigureAwait(false);
        CopyInstallPayload(sourceDirectory, destinationDirectory);
        WriteAgentConfig(destinationDirectory, options);
        await CreateServiceAsync(serviceName, executablePath, cancellationToken).ConfigureAwait(false);
        await RunScAsync(cancellationToken, "description", serviceName, "WinPatchAgent endpoint service").ConfigureAwait(false);
        await RunScAsync(cancellationToken, "failure", serviceName, "reset=", "86400", "actions=", "restart/60000/restart/60000//0").ConfigureAwait(false);
        await RunScAsync(cancellationToken, "start", serviceName).ConfigureAwait(false);

        Console.WriteLine($"Service installed and started: {serviceName}");
    }

    private static async Task UninstallAsync(InstallerCommandOptions options, CancellationToken cancellationToken)
    {
        var installDirectory = options.InstallDirectory;
        var serviceName = options.ServiceName;

        Console.WriteLine($"Uninstalling service {serviceName}");
        await StopAndDeleteServiceIfPresentAsync(serviceName, cancellationToken).ConfigureAwait(false);

        if (Directory.Exists(installDirectory))
        {
            Directory.Delete(installDirectory, recursive: true);
        }

        if (options.PurgeState && Directory.Exists(options.StorageRoot))
        {
            Directory.Delete(options.StorageRoot, recursive: true);
        }

        Console.WriteLine($"Service removed: {serviceName}");
    }

    private static void EnsureAdministrator()
    {
        using var identity = WindowsIdentity.GetCurrent();
        var principal = new WindowsPrincipal(identity);
        if (!principal.IsInRole(WindowsBuiltInRole.Administrator))
        {
            throw new InvalidOperationException("Administrator rights are required to install or uninstall the service.");
        }
    }

    private static async Task StopAndDeleteServiceIfPresentAsync(string serviceName, CancellationToken cancellationToken)
    {
        var exists = await ServiceExistsAsync(serviceName, cancellationToken).ConfigureAwait(false);
        if (!exists)
        {
            return;
        }

        await RunScAsync(cancellationToken, "stop", serviceName, allowNonZeroExitCode: true).ConfigureAwait(false);

        for (var attempt = 0; attempt < 20; attempt++)
        {
            var status = await QueryServiceStateAsync(serviceName, cancellationToken).ConfigureAwait(false);
            if (status is null || !status.Contains("STOP_PENDING", StringComparison.OrdinalIgnoreCase))
            {
                break;
            }

            await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken).ConfigureAwait(false);
        }

        await RunScAsync(cancellationToken, "delete", serviceName).ConfigureAwait(false);

        for (var attempt = 0; attempt < 20; attempt++)
        {
            if (!await ServiceExistsAsync(serviceName, cancellationToken).ConfigureAwait(false))
            {
                return;
            }

            await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken).ConfigureAwait(false);
        }

        throw new InvalidOperationException($"Timed out waiting for service deletion: {serviceName}");
    }

    private static async Task<bool> ServiceExistsAsync(string serviceName, CancellationToken cancellationToken)
    {
        var result = await RunScAsync(cancellationToken, "query", serviceName, allowNonZeroExitCode: true).ConfigureAwait(false);
        return result.ExitCode == 0
            && !result.CombinedOutput.Contains("does not exist", StringComparison.OrdinalIgnoreCase)
            && !result.CombinedOutput.Contains("1060", StringComparison.OrdinalIgnoreCase);
    }

    private static async Task<string?> QueryServiceStateAsync(string serviceName, CancellationToken cancellationToken)
    {
        var result = await RunScAsync(cancellationToken, "query", serviceName, allowNonZeroExitCode: true).ConfigureAwait(false);
        if (result.ExitCode != 0)
        {
            return null;
        }

        return result.CombinedOutput;
    }

    private static async Task CreateServiceAsync(string serviceName, string executablePath, CancellationToken cancellationToken)
    {
        if (!File.Exists(executablePath))
        {
            throw new FileNotFoundException("Expected service binary was not found after staging the install payload.", executablePath);
        }

        await RunScAsync(
            cancellationToken,
            "create",
            serviceName,
            "binPath=",
            $"\"{executablePath}\"",
            "start=",
            "auto").ConfigureAwait(false);
    }

    private static void CopyInstallPayload(string sourceDirectory, string destinationDirectory)
    {
        if (PathEquals(sourceDirectory, destinationDirectory))
        {
            return;
        }

        foreach (var directory in Directory.EnumerateDirectories(sourceDirectory, "*", SearchOption.AllDirectories))
        {
            var relativePath = Path.GetRelativePath(sourceDirectory, directory);
            Directory.CreateDirectory(Path.Combine(destinationDirectory, relativePath));
        }

        foreach (var filePath in Directory.EnumerateFiles(sourceDirectory, "*", SearchOption.AllDirectories))
        {
            var relativePath = Path.GetRelativePath(sourceDirectory, filePath);
            var destinationPath = Path.Combine(destinationDirectory, relativePath);
            Directory.CreateDirectory(Path.GetDirectoryName(destinationPath)!);
            File.Copy(filePath, destinationPath, overwrite: true);
        }
    }

    private static bool PathEquals(string left, string right)
    {
        return string.Equals(
            Path.GetFullPath(left).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar),
            Path.GetFullPath(right).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar),
            StringComparison.OrdinalIgnoreCase);
    }

    private static void WriteAgentConfig(string installDirectory, InstallerCommandOptions options)
    {
        var configPath = Path.Combine(installDirectory, "appsettings.Production.json");
        var payload = new
        {
            Agent = new
            {
                ServiceName = options.ServiceName,
                BackendBaseUrl = options.BackendUrl,
                EnrollmentKey = options.EnrollmentKey,
                AgentChannel = options.AgentChannel,
                StorageRoot = options.StorageRoot,
                RequestTimeoutSeconds = 30,
                LoopDelaySeconds = 15,
                HeartbeatIntervalSeconds = 300,
                InventoryIntervalSeconds = 21600,
                JobPollIntervalSeconds = 120,
                EnableStubJobExecution = true,
                StubJobDurationSeconds = 20,
                EnableAptJobExecution = false,
                EnableWindowsUpdateJobExecution = true,
                WindowsUpdateCommandTimeoutSeconds = 5400,
                EnableWindowsPowerShellScriptExecution = true,
                WindowsPowerShellScriptCommandTimeoutSeconds = 3600,
                EnableMacSoftwareUpdateJobExecution = false,
                MacSoftwareUpdateCommandTimeoutSeconds = 5400,
                WindowsSelfUpdatePackageUrl = options.WindowsSelfUpdatePackageUrl
            }
        };

        var json = JsonSerializer.Serialize(payload, new JsonSerializerOptions
        {
            WriteIndented = true
        });

        File.WriteAllText(configPath, json + Environment.NewLine, new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
    }

    private static async Task<ScCommandResult> RunScAsync(
        CancellationToken cancellationToken,
        params string[] arguments)
    {
        return await RunScAsync(cancellationToken, arguments, allowNonZeroExitCode: false).ConfigureAwait(false);
    }

    private static async Task<ScCommandResult> RunScAsync(
        CancellationToken cancellationToken,
        string command,
        string serviceName,
        bool allowNonZeroExitCode)
    {
        return await RunScAsync(cancellationToken, new[] { command, serviceName }, allowNonZeroExitCode).ConfigureAwait(false);
    }

    private static async Task<ScCommandResult> RunScAsync(
        CancellationToken cancellationToken,
        string command,
        string serviceName,
        string extraArgument,
        bool allowNonZeroExitCode)
    {
        return await RunScAsync(cancellationToken, new[] { command, serviceName, extraArgument }, allowNonZeroExitCode).ConfigureAwait(false);
    }

    private static async Task<ScCommandResult> RunScAsync(
        CancellationToken cancellationToken,
        IEnumerable<string> arguments,
        bool allowNonZeroExitCode)
    {
        var argumentList = arguments.ToArray();
        using var process = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = "sc.exe",
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true
            }
        };

        foreach (var argument in argumentList)
        {
            process.StartInfo.ArgumentList.Add(argument);
        }

        process.Start();

        var stdoutTask = process.StandardOutput.ReadToEndAsync(cancellationToken);
        var stderrTask = process.StandardError.ReadToEndAsync(cancellationToken);

        await process.WaitForExitAsync(cancellationToken).ConfigureAwait(false);

        var stdout = await stdoutTask.ConfigureAwait(false);
        var stderr = await stderrTask.ConfigureAwait(false);
        var result = new ScCommandResult(process.ExitCode, stdout, stderr);

        if (!allowNonZeroExitCode && result.ExitCode != 0)
        {
            throw new InvalidOperationException(
                $"sc.exe {string.Join(" ", argumentList)} failed with exit code {result.ExitCode}: {result.CombinedOutput}");
        }

        return result;
    }

    private sealed record ScCommandResult(int ExitCode, string StandardOutput, string StandardError)
    {
        public string CombinedOutput =>
            string.Join(
                Environment.NewLine,
                new[] { StandardOutput.Trim(), StandardError.Trim() }.Where(value => !string.IsNullOrWhiteSpace(value)));
    }

    private sealed class InstallerCommandOptions
    {
        public string BackendUrl { get; private set; } = string.Empty;
        public string EnrollmentKey { get; private set; } = string.Empty;
        public string ServiceName { get; private set; } = DefaultServiceName;
        public string InstallDirectory { get; private set; } = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles),
            DefaultInstallDirectoryName);
        public string StorageRoot { get; private set; } = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            DefaultInstallDirectoryName,
            "state");
        public string AgentChannel { get; private set; } = DefaultAgentChannel;
        public string WindowsSelfUpdatePackageUrl { get; private set; } = AgentOptions.DefaultWindowsPackageUrl;
        public bool PurgeState { get; private set; }

        public static InstallerCommandOptions Parse(string[] args)
        {
            var options = new InstallerCommandOptions();

            for (var index = 0; index < args.Length; index++)
            {
                var token = args[index].Trim();
                if (token is "--purge-state")
                {
                    options.PurgeState = true;
                    continue;
                }

                if (!token.StartsWith("--", StringComparison.Ordinal))
                {
                    throw new InvalidOperationException($"Unexpected argument: {token}");
                }

                if (index + 1 >= args.Length)
                {
                    throw new InvalidOperationException($"Missing value for argument: {token}");
                }

                var value = args[++index];
                switch (token)
                {
                    case "--backend-url":
                        options.BackendUrl = value.Trim();
                        break;
                    case "--enrollment-key":
                        options.EnrollmentKey = value;
                        break;
                    case "--service-name":
                        options.ServiceName = string.IsNullOrWhiteSpace(value) ? DefaultServiceName : value.Trim();
                        break;
                    case "--install-dir":
                        options.InstallDirectory = Path.GetFullPath(value);
                        break;
                    case "--storage-root":
                        options.StorageRoot = Path.GetFullPath(value);
                        break;
                    case "--agent-channel":
                        options.AgentChannel = string.IsNullOrWhiteSpace(value) ? DefaultAgentChannel : value.Trim();
                        break;
                    case "--windows-self-update-package-url":
                        options.WindowsSelfUpdatePackageUrl = string.IsNullOrWhiteSpace(value)
                            ? AgentOptions.DefaultWindowsPackageUrl
                            : value.Trim();
                        break;
                    default:
                        throw new InvalidOperationException($"Unsupported argument: {token}");
                }
            }

            return options;
        }
    }
}
