using System.Net.Http.Headers;
using PatchAgent.Service.Abstractions;
using PatchAgent.Service.Configuration;
using PatchAgent.Service.Modules;
using PatchAgent.Service.Services;
using Microsoft.Extensions.Options;

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
builder.Services.AddSingleton<LinuxAptJobExecutor>();
builder.Services.AddSingleton<WindowsUpdateJobExecutor>();
builder.Services.AddSingleton<MacSoftwareUpdateJobExecutor>();
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
