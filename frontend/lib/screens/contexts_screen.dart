import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../models/context_model.dart';
import '../services/api_service.dart';

class ContextsScreen extends StatefulWidget {
  const ContextsScreen({super.key});

  @override
  State<ContextsScreen> createState() => _ContextsScreenState();
}

class _ContextsScreenState extends State<ContextsScreen> {
  final ApiService _apiService = ApiService();
  List<ContextModel> _contexts = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadContexts();
  }

  Future<void> _loadContexts() async {
    setState(() => _isLoading = true);
    try {
      final list = await _apiService.getContexts();
      setState(() {
        _contexts = list.map((e) => ContextModel.fromJson(e)).toList();
      });
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: $e')));
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _showForm([ContextModel? contextModel]) {
    showDialog(
      context: context,
      builder: (ctx) => ContextFormDialog(
        contextModel: contextModel,
        onSave: (data) async {
          Navigator.pop(ctx);
          if (contextModel == null) {
            await _apiService.createContext(data);
          } else {
            await _apiService.updateContext(contextModel.id, data);
          }
          _loadContexts();
        },
      ),
    );
  }

  void _deleteContext(int id) async {
    if (await showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Confirm'),
        content: const Text('Delete this context?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
        ],
      ),
    ) == true) {
      await _apiService.deleteContext(id);
      _loadContexts();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F0F12),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0F0F12),
        title: Text('Contexts', style: GoogleFonts.outfit(color: Colors.white)),
        actions: [
          IconButton(onPressed: () => _showForm(), icon: const Icon(Icons.add, color: Colors.white)),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: _contexts.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                final item = _contexts[index];
                return Container(
                  decoration: BoxDecoration(
                    color: const Color(0xFF18181B),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: ListTile(
                    title: Text(item.name, style: GoogleFonts.inter(color: Colors.white, fontWeight: FontWeight.w600)),
                    subtitle: Text(
                      item.description ?? 'No description',
                      style: GoogleFonts.inter(color: Colors.grey, fontSize: 12),
                    ),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          icon: const Icon(Icons.edit, color: Colors.blueAccent),
                          onPressed: () => _showForm(item),
                        ),
                        IconButton(
                          icon: const Icon(Icons.delete, color: Colors.redAccent),
                          onPressed: () => _deleteContext(item.id),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
    );
  }
}

class ContextFormDialog extends StatefulWidget {
  final ContextModel? contextModel;
  final Function(Map<String, dynamic>) onSave;

  const ContextFormDialog({super.key, this.contextModel, required this.onSave});

  @override
  State<ContextFormDialog> createState() => _ContextFormDialogState();
}

class _ContextFormDialogState extends State<ContextFormDialog> {
  final _nameCtrl = TextEditingController();
  final _descCtrl = TextEditingController();
  final _textCtrl = TextEditingController();
  final _tablesCtrl = TextEditingController(); // Comma separated for now, could be improved
  bool _isTableMode = false;

  @override
  void initState() {
    super.initState();
    if (widget.contextModel != null) {
      _nameCtrl.text = widget.contextModel!.name;
      _descCtrl.text = widget.contextModel!.description ?? '';
      final content = widget.contextModel!.content ?? {};
      if (content.containsKey('tables')) {
        _isTableMode = true;
        _tablesCtrl.text = (content['tables'] as List).join(', ');
      } else {
        _isTableMode = false;
        _textCtrl.text = content['text'] ?? '';
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: const Color(0xFF27272A),
      title: Text(widget.contextModel == null ? 'New Context' : 'Edit Context',
          style: GoogleFonts.outfit(color: Colors.white)),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _buildField('Name', _nameCtrl),
            const SizedBox(height: 12),
            _buildField('Description', _descCtrl),
            const SizedBox(height: 12),
            Row(
              children: [
                const Text('Type: ', style: TextStyle(color: Colors.grey)),
                ChoiceChip(
                  label: const Text('Text'),
                  selected: !_isTableMode,
                  onSelected: (v) => setState(() => _isTableMode = !v),
                ),
                const SizedBox(width: 8),
                ChoiceChip(
                  label: const Text('Tables'),
                  selected: _isTableMode,
                  onSelected: (v) => setState(() => _isTableMode = v),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (_isTableMode)
              _buildField('Tables (comma separated)', _tablesCtrl)
            else
              _buildField('Content Text', _textCtrl, maxLines: 5),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        ElevatedButton(
          onPressed: () {
            final content = _isTableMode
                ? {'tables': _tablesCtrl.text.split(',').map((e) => e.trim()).toList()}
                : {'text': _textCtrl.text};
            widget.onSave({
              'name': _nameCtrl.text,
              'description': _descCtrl.text,
              'content': content,
            });
          },
          child: const Text('Save'),
        ),
      ],
    );
  }

  Widget _buildField(String label, TextEditingController ctrl, {int maxLines = 1}) {
    return TextField(
      controller: ctrl,
      maxLines: maxLines,
      style: const TextStyle(color: Colors.white),
      decoration: InputDecoration(
        labelText: label,
        labelStyle: const TextStyle(color: Colors.grey),
        enabledBorder: OutlineInputBorder(borderSide: BorderSide(color: Colors.grey[700]!)),
        focusedBorder: const OutlineInputBorder(borderSide: BorderSide(color: Colors.blueAccent)),
      ),
    );
  }
}

