using System.Text.Json;

namespace PatchAgent.Service.Models;

public sealed class TelemetryEvent
{
    public string EventId { get; set; } = Guid.NewGuid().ToString();

    public DateTimeOffset TimestampUtc { get; set; } = DateTimeOffset.UtcNow;

    public string EventType { get; set; } = string.Empty;

    public string PayloadJson { get; set; } = "{}";

    public static TelemetryEvent Create(string eventType, object payload)
    {
        return new TelemetryEvent
        {
            EventType = eventType,
            PayloadJson = JsonSerializer.Serialize(payload)
        };
    }
}
