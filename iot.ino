#define BLYNK_TEMPLATE_ID "TMPL6Sl440T2l"
#define BLYNK_TEMPLATE_NAME "IOT BASED TEMPERATURE MONITORING SYSTEM"
#define BLYNK_AUTH_TOKEN "Z5AzY6vAwCLEA4OYvjxLZMkpVsVzC3jL"

#define BLYNK_PRINT Serial

#include <ESP8266WiFi.h>
#include <BlynkSimpleEsp8266.h>
#include <DHT.h>
#include <ESP_Mail_Client.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>

char auth[] = BLYNK_AUTH_TOKEN;
char ssid[] = "EnVy";
char pass[] = "b!gd@ddyN0+4175";

#define DHTPIN D5
#define DHTTYPE DHT22
DHT dht(DHTPIN, DHTTYPE);

#define BUZZER D7
#define LEDPIN D6

BlynkTimer timer;

#define SMTP_HOST "smtp.gmail.com"
#define SMTP_PORT 465

#define AUTHOR_EMAIL "iotbasedtemperaturesystem@gmail.com"
#define AUTHOR_PASSWORD "osswmataabtehyvs"
#define RECIPIENT_EMAIL "siotbased@gmail.com"

SMTPSession smtp;

String lastStatus = "";
bool pendingEmail = false;
String pendingEmailSubject = "";
String pendingEmailMessage = "";
bool firstReading = true;
unsigned long bootTime = 0;
bool bootEmailSent = false;
bool sendingData = false;  // Guard against overlapping HTTP requests
bool sendingEmail = false; // Guard against email blocking sensor reads

// ── LOCAL TESTING: set to true to send data to your local XAMPP server ──
// Set to false before uploading for production (sends to iot.sulusc.online)
#define LOCAL_TEST false
#define LOCAL_SERVER "192.168.1.171"
#define LOCAL_PORT 8080

void sendToDatabase(float temperature, float humidity)
{
  if (WiFi.status() != WL_CONNECTED)
    return;

#if LOCAL_TEST
  WiFiClient client;
#else
  BearSSL::WiFiClientSecure client;
  client.setInsecure();
#endif

  for (int attempt = 0; attempt < 2; attempt++)
  {
    HTTPClient http;

#if LOCAL_TEST
    String url = "http://" LOCAL_SERVER ":" + String(LOCAL_PORT) + "/iot-temp-monitoring/log.php?temp=" + String(temperature) + "&hum=" + String(humidity);
    http.begin(client, url);
    http.setTimeout(3000);
#else
    String url = "https://iot-temp-monitoring-iq6o.onrender.com/log.php?temp=" + String(temperature) + "&hum=" + String(humidity);
    http.begin(client, url);
    http.setTimeout(10000);
#endif

    int httpCode = http.GET();
    http.end();

    if (httpCode == 200)
    {
      Serial.println("Database: OK");
      return;
    }
    else
    {
      Serial.println("DB Error (attempt " + String(attempt + 1) + "): " + String(httpCode));
    }

    if (attempt == 0)
    {
      delay(1000);
      yield();
    }
  }
}

// Quick sensor read + DB push to keep device online during email
void sendHeartbeat()
{
  float h = dht.readHumidity();
  float t = dht.readTemperature();
  if (isnan(h) || isnan(t))
    return;
  sendToDatabase(t, h);
  if (Blynk.connected())
  {
    Blynk.virtualWrite(V0, t);
    Blynk.virtualWrite(V1, h);
  }
}

void sendEmail(String subject, String message)
{
  sendingEmail = true;
  Serial.println("Sending email...");

  SMTP_Message msg;

  msg.sender.name = "ESP8266 Temp Monitor";
  msg.sender.email = AUTHOR_EMAIL;
  msg.subject = subject;
  msg.addRecipient("Receiver", RECIPIENT_EMAIL);

  msg.text.content = message;
  msg.text.charSet = "us-ascii";
  msg.text.transfer_encoding = Content_Transfer_Encoding::enc_7bit;

  ESP_Mail_Session session;
  session.server.host_name = SMTP_HOST;
  session.server.port = SMTP_PORT;
  session.login.email = AUTHOR_EMAIL;
  session.login.password = AUTHOR_PASSWORD;

  yield();

  if (!smtp.connect(&session))
  {
    Serial.println("SMTP connect failed");
    sendingEmail = false;
    return;
  }

  yield();

  if (!MailClient.sendMail(&smtp, &msg))
  {
    Serial.println("Email send failed");
  }
  else
  {
    Serial.println("Email sent OK");
  }

  smtp.closeSession();
  yield();
  sendingEmail = false;
}

void sendDHTData()
{
  // Prevent overlapping calls if previous HTTP request or email is still running
  if (sendingData || sendingEmail)
    return;

  float humidity = dht.readHumidity();
  float temperature = dht.readTemperature();

  if (isnan(humidity) || isnan(temperature))
    return;

  if (Blynk.connected())
  {
    Blynk.virtualWrite(V0, temperature);
    Blynk.virtualWrite(V1, humidity);
  }

  sendingData = true;
  sendToDatabase(temperature, humidity);
  sendingData = false;

  String status = "";
  String solution = "";

  if (temperature >= 15 && temperature <= 17)
  {
    status = "LOW TEMPERATURE";
    solution = "Environment too cold. Consider heating.";
    digitalWrite(LEDPIN, HIGH);
    digitalWrite(BUZZER, LOW);
  }
  else if (temperature >= 18 && temperature <= 24)
  {
    status = "STABLE";
    solution = "Temperature is normal.";
    digitalWrite(LEDPIN, LOW);
    digitalWrite(BUZZER, LOW);
  }
  else if (temperature >= 25 && temperature <= 30)
  {
    status = "WARNING";
    solution = "Temperature rising. Monitor closely.";
    digitalWrite(LEDPIN, HIGH);
    digitalWrite(BUZZER, LOW);
  }
  else if (temperature > 30)
  {
    status = "CRITICAL";
    solution = "Temperature too high! Immediate action required.";
    digitalWrite(LEDPIN, HIGH);
    digitalWrite(BUZZER, HIGH);
  }
  else
  {
    status = "EXTREME LOW";
    solution = "Temperature extremely low!";
    digitalWrite(LEDPIN, HIGH);
    digitalWrite(BUZZER, HIGH);
  }

  // Send initial boot email after 60-second warm-up
  if (!bootEmailSent && millis() - bootTime > 60000)
  {
    bootEmailSent = true;
    firstReading = false;
    lastStatus = status;
    pendingEmail = true;
    pendingEmailSubject = "DEVICE ONLINE - STATUS: " + status;
    pendingEmailMessage = "Device has booted up.\n\nCurrent Status: " + status +
                          "\n\nCurrent Temperature: " + String(temperature) + " °C" +
                          "\nHumidity: " + String(humidity) + " %";
  }
  else if (bootEmailSent && status != lastStatus)
  {
    pendingEmail = true;
    pendingEmailSubject = "TEMPERATURE STATUS: " + status;
    pendingEmailMessage = "Temperature Level: " + status +
                          "\n\nCurrent Temperature: " + String(temperature) + " °C" +
                          "\nHumidity: " + String(humidity) + " %";
    lastStatus = status;
  }

  Blynk.virtualWrite(V2, status);
  Blynk.virtualWrite(V3, solution);

  Serial.print("Temperature: ");
  Serial.println(temperature);
  Serial.print("Humidity: ");
  Serial.println(humidity);
  Serial.print("Status: ");
  Serial.println(status);
  Serial.println("-----------------");
}

void connectWiFi()
{
  if (WiFi.status() == WL_CONNECTED)
    return;

  Serial.print("Connecting to WiFi");
  WiFi.disconnect();
  delay(100);
  WiFi.begin(ssid, pass);

  unsigned long wifiStart = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - wifiStart < 20000)
  {
    delay(500);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED)
  {
    Serial.println("WiFi connected: " + WiFi.localIP().toString());
  }
  else
  {
    Serial.println("WiFi failed - will retry later");
  }
}

void setup()
{
  Serial.begin(9600);
  delay(3000); // Allow power to stabilize on external supply

  pinMode(BUZZER, OUTPUT);
  pinMode(LEDPIN, OUTPUT);
  digitalWrite(BUZZER, LOW);
  digitalWrite(LEDPIN, LOW);
  dht.begin();

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(true);
  connectWiFi();

  Blynk.config(auth);
  if (WiFi.status() == WL_CONNECTED)
  {
    Blynk.connect(5000);
  }

  bootTime = millis();
  timer.setInterval(10000L, sendDHTData); // 10 seconds — allows time for HTTPS + retry on Render
}

unsigned long lastWiFiRetry = 0;

void loop()
{
  // Reconnect WiFi if disconnected (retry every 30 seconds)
  if (WiFi.status() != WL_CONNECTED)
  {
    if (millis() - lastWiFiRetry > 30000)
    {
      lastWiFiRetry = millis();
      connectWiFi();
    }
  }

  if (WiFi.status() == WL_CONNECTED)
  {
    if (!Blynk.connected())
    {
      Blynk.connect(3000);
    }
    Blynk.run();
  }

  yield(); // Feed the watchdog timer to prevent crashes
  timer.run();

  // Log free heap every 30s for crash debugging
  static unsigned long lastHeapLog = 0;
  if (millis() - lastHeapLog > 30000)
  {
    lastHeapLog = millis();
    Serial.println("Free heap: " + String(ESP.getFreeHeap()));
  }

  if (pendingEmail && !sendingData && !sendingEmail)
  {
    pendingEmail = false;
    String subj = pendingEmailSubject;
    String body = pendingEmailMessage;
    sendEmail(subj, body);
  }
}