using System.Net.Http.Headers;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Modules;
using PatchAgent.Service.Services;
using Microsoft.Extensions.Options;

int? installerExitCode = null;
if (OperatingSystem.IsWindows())
{
    installerExitCode = await WindowsServiceInstaller.TryRunAsync(args);
}
else if (args.Length > 0 && (string.Equals(args[0], "install", StringComparison.OrdinalIgnoreCase)
    || string.Equals(args[0], "uninstall", StringComparison.OrdinalIgnoreCase)))
{
    Console.Error.WriteLine("The install and uninstall commands are only supported on Windows.");
    Environment.ExitCode = 1;
    return;
}

if (installerExitCode.HasValue)
{
    Environment.ExitCode = installerExitCode.Value;
    return;
}

var builder = Host.CreateApplicationBuilder(args);

builder.Services.AddWindowsService(options =>
{
    options.ServiceName = "PatchAgentSvc";
});
builder.Services.AddSystemd();

builder.Configuration.AddEnvironmentVariables(prefix: "PATCHAGENT_");

builder.Logging.AddSimpleConsole(options =>
{
    options.SingleLine = true;
    options.TimestampFormat = "yyyy-MM-dd HH:mm:ss ";
});

builder.Services.Configure<AgentOptions>(builder.Configuration.GetSection(AgentOptions.SectionName));

builder.Services.AddSingleton<IPathProvider, SystemPathProvider>();
builder.Services.AddSingleton<ILocalStateStore, JsonFileStateStore>();
builder.Services.AddSingleton<ITelemetryQueue, FileTelemetryQueue>();
builder.Services.AddSingleton<IAgentIdentityManager, BootstrapIdentityManager>();
builder.Services.AddSingleton<IInventoryCollector, SystemInventoryCollector>();
builder.Services.AddSingleton<StubJobExecutor>();
builder.Services.AddSingleton<AgentSelfUpdateJobExecutor>();
builder.Services.AddSingleton<LinuxAptJobExecutor>();
builder.Services.AddSingleton<WindowsUpdateJobExecutor>();
builder.Services.AddSingleton<WindowsPowerShellScriptJobExecutor>();
builder.Services.AddSingleton<MacSoftwareUpdateJobExecutor>();
builder.Services.AddSingleton<MacShellScriptJobExecutor>();
builder.Services.AddSingleton<SoftwareInstallJobExecutor>();
builder.Services.AddSingleton<SoftwareSearchJobExecutor>();
builder.Services.AddSingleton<IJobExecutor, DispatchingJobExecutor>();
builder.Services.AddHttpClient<IPolicyClient, HttpPolicyClient>((serviceProvider, client) =>
{
    var options = serviceProvider.GetRequiredService<IOptions<AgentOptions>>().Value;

    client.BaseAddress = new Uri(options.BackendBaseUrl.TrimEnd('/') + "/");
    client.Timeout = TimeSpan.FromSeconds(options.RequestTimeoutSeconds);
    client.DefaultRequestHeaders.Accept.Add(
        new MediaTypeWithQualityHeaderValue("application/json"));
    client.DefaultRequestHeaders.UserAgent.ParseAdd("PatchAgent.Service/0.1");
});
builder.Services.AddSingleton<AgentCoordinator>();
builder.Services.AddHostedService<AgentWorker>();

await builder.Build().RunAsync();
