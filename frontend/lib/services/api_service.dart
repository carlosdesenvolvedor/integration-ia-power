import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:flutter/foundation.dart';

class ApiService {
  // Use 10.0.2.2 for Android Emulator, localhost for iOS/Web, or specific IP for physical devices
  static String get baseUrl {
    if (kIsWeb) return 'http://localhost:9600';
    // Replace 192.168.15.6 with your actual local IP address
    return 'http://192.168.15.6:9600'; 
  }

  Future<Map<String, dynamic>> createTable(String description) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/create-table'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'description': description}),
    );
    return _handleResponse(response);
  }

  Future<Map<String, dynamic>> query(String question) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/query'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'question': question}),
    );
    return _handleResponse(response);
  }

  Future<Map<String, dynamic>> command(String command) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/command'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'command': command}),
    );
    return _handleResponse(response);
  }

  Future<dynamic> analyzeQuery(String question, {List<String>? contextTables, int? contextId}) async {
    final body = <String, dynamic>{'question': question};
    if (contextTables != null && contextTables.isNotEmpty) {
      body['context_tables'] = contextTables;
    }
    if (contextId != null) {
      body['context_id'] = contextId;
    }
    final response = await http.post(
      Uri.parse('$baseUrl/ai/analyze-query'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode(body),
    );
    return _handleResponse(response);
  }

  Future<Map<String, dynamic>> analyzeInsight(String question, Map<String, dynamic> data) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/analyze-insight'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'question': question, 'data': data}),
    );
    return _handleResponse(response);
  }

  Future<Map<String, dynamic>> migrate(String table, String command) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/migrate'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'table': table, 'command': command}),
    );
    return _handleResponse(response);
  }

  Future<void> dropTable(String table) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/drop-table'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'table': table}),
    );
     _handleResponse(response);
  }

  Future<String> chatFree(String message) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/chat-free'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'message': message}),
    );
    final data = _handleResponse(response);
    return data['reply'];
  }

  Stream<String> chatFreeStream(String message, {int? contextId}) async* {
    final client = http.Client();
    final request = http.Request('POST', Uri.parse('$baseUrl/ai/chat-free-stream'));
    request.headers['Content-Type'] = 'application/json';
    request.body = jsonEncode({'message': message, 'context_id': contextId});

    final response = await client.send(request);

    if (response.statusCode != 200) {
      final body = await response.stream.transform(utf8.decoder).join();
      throw Exception('Failed to stream chat: $body');
    }

    // Emit as soon as bytes arrive (no need to wait for full lines)
    yield* response.stream
        .transform(utf8.decoder)
        .expand((chunk) => chunk.split('')); // stream character by character for smoother UI
  }

  Future<List<dynamic>> getContexts() async {
    final response = await http.get(Uri.parse('$baseUrl/contexts'));
    return _handleResponse(response) as List<dynamic>;
  }

  Future<dynamic> createContext(Map<String, dynamic> data) async {
    final response = await http.post(
      Uri.parse('$baseUrl/contexts'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode(data),
    );
    return _handleResponse(response);
  }

  Future<dynamic> updateContext(int id, Map<String, dynamic> data) async {
    final response = await http.put(
      Uri.parse('$baseUrl/contexts/$id'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode(data),
    );
    return _handleResponse(response);
  }

  Future<void> deleteContext(int id) async {
    final response = await http.delete(Uri.parse('$baseUrl/contexts/$id'));
    _handleResponse(response);
  }

  Future<Map<String, dynamic>> generateCrud(String table) async {
    final response = await http.post(
      Uri.parse('$baseUrl/ai/generate-crud'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'table': table}),
    );
    return _handleResponse(response);
  }

  Future<List<String>> getTables() async {
    final response = await http.get(Uri.parse('$baseUrl/ai/tables'));
    final data = _handleResponse(response);
    return List<String>.from(data['tables']);
  }

  Future<Map<String, dynamic>> getTableData(String table) async {
    final response = await http.get(Uri.parse('$baseUrl/ai/table-data?table=$table'));
    final data = _handleResponse(response);
    return Map<String, dynamic>.from(data['data']);
  }

  dynamic _handleResponse(http.Response response) {
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return jsonDecode(response.body);
    } else {
      throw Exception('Erro na requisição: ${response.statusCode} - ${response.body}');
    }
  }
}
