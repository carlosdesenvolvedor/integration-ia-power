import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:flutter_markdown/flutter_markdown.dart';
import 'dart:convert';
import '../services/api_service.dart';
import '../widgets/custom_loading_widget.dart';
import 'explore_screen.dart';
import 'contexts_screen.dart';
import '../models/context_model.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final TextEditingController _controller = TextEditingController();
  final ScrollController _scrollController = ScrollController();
  final ApiService _apiService = ApiService();
  final List<Map<String, String>> _messages = [];
  bool _isLoading = false;
  Map<String, dynamic>? _lastAnalyzeData;
  List<String> _lastAnalyzeTables = [];
  bool _isTyping = false;
  
  // Sidebar states
  bool _isSidebarOpen = true;
  int _selectedNavIndex = 1; // 1 = Chat
  String _selectedMode = 'analyze'; // analyze, create-table, query, command, migrate
  List<String> _selectedContextTables = []; // Changed to List
  List<ContextModel> _availableContexts = [];
  int? _selectedContextId;

  @override
  void initState() {
    super.initState();
    _loadContexts();
  }

  Future<void> _loadContexts() async {
    try {
      final list = await _apiService.getContexts();
      setState(() {
        _availableContexts = list.map((e) => ContextModel.fromJson(e)).toList();
      });
    } catch (e) {
      // Ignore error for now
    }
  }

  void _sendMessage() async {
    final text = _controller.text.trim();
    if (text.isEmpty) return;

    _controller.clear();
    setState(() {
      _messages.add({'role': 'user', 'content': text});
      _isLoading = true;
      // Auto-switch to Chat view so user sees the conversation
      if (_selectedNavIndex != 1) {
        _selectedNavIndex = 1;
      }
    });
    
    // Auto-scroll to bottom
    Future.delayed(const Duration(milliseconds: 100), () {
      if (_scrollController.hasClients) {
        _scrollController.animateTo(
          _scrollController.position.maxScrollExtent,
          duration: const Duration(milliseconds: 300),
          curve: Curves.easeOut,
        );
      }
    });

    try {
      // Use sticky context if tables are selected
      String finalPrompt = text;
      if (_selectedContextTables.isNotEmpty) {
         // Create a readable list for the prompt
         final params = _selectedContextTables.join(', ');
        finalPrompt = "Considerando as tabelas [${params}], $text";
      }

      Map<String, dynamic> response;

      // SPECIAL HANDLE FOR ANALYZE (ASYNC UI)
      if (_selectedMode == 'analyze') {
          // Reuse last local data if context tables are the same
          Map<String, dynamic> queryResponse;
          if (_selectedContextTables.isNotEmpty &&
              _lastAnalyzeData != null &&
              _sameTables(_selectedContextTables, _lastAnalyzeTables)) {
            queryResponse = {'data': _lastAnalyzeData!, 'from_cache': true};
          } else {
            queryResponse = await _apiService.analyzeQuery(
              finalPrompt,
              contextTables: _selectedContextTables,
              contextId: _selectedContextId,
            );
          }

          String partialReply = "";
          dynamic queryData; 
          
          if (queryResponse.containsKey('error')) {
             setState(() {
                _messages.add({'role': 'assistant', 'content': 'Erro na consulta: ${queryResponse['error']}'}); 
                _isLoading = false;
             });
             return;
          }

          if (queryResponse.containsKey('data')) {
             queryData = queryResponse['data'];
             final results = queryResponse['data'];
             int count = 0;
             dynamic displayData = results;

             if (results is List) {
               count = results.length;
             } else if (results is Map) {
               if (results.containsKey('rows') && results['rows'] is List) {
                 count = (results['rows'] as List).length;
                 displayData = results['rows'];
               } else if (results.containsKey('tables') && results['tables'] is List) {
                 // Sum rows across tables
                 int total = 0;
                 final tables = results['tables'] as List;
                 for (final t in tables) {
                   if (t is Map && t.containsKey('data')) {
                     final data = t['data'];
                     if (data is Map && data['rows'] is List) {
                       total += (data['rows'] as List).length;
                     }
                   }
                 }
                 count = total;
                 displayData = results['tables'];
               }
             }
             
             // Immediate feedback to user
             partialReply = "✅ **Dados Encontrados:** $count registros.\n```json\n${jsonEncode(displayData)}\n```\n\n🤔 *Analisando esses dados com IA Inteligente (isto pode levar alguns segundos)...*";
             
             setState(() {
                 _messages.add({'role': 'assistant', 'content': partialReply});
             });
             
             // Scroll to show data
             _scrollToBottom();
          }

          // 2. Second Step: Get Insight (Slow)
          if (queryData != null) {
              // Ensure we pass a Map to the API for 'data'. If queryData is List, wrap it.
              Map<String, dynamic> dataPayload;
              if (queryData is List) {
                  dataPayload = {'rows': queryData};
              } else if (queryData is Map && queryData.containsKey('tables')) {
                  dataPayload = {
                    'tables': List<Map<String, dynamic>>.from(
                      (queryData['tables'] as List).whereType<Map<String, dynamic>>(),
                    )
                  };
              } else {
                  dataPayload = Map<String, dynamic>.from(queryData as Map);
              }

              // Store last data + tables for reuse
              _lastAnalyzeData = dataPayload;
              _lastAnalyzeTables = List<String>.from(_selectedContextTables);

              final insightResponse = await _apiService.analyzeInsight(finalPrompt, dataPayload);
              
              String finalInsight = "";
              if (insightResponse.containsKey('insight')) {
                  finalInsight = "\n\n💡 **Insight:**\n${insightResponse['insight']}";
              } else if (insightResponse.containsKey('error')) {
                  finalInsight = "\n\n❌ Erro na análise: ${insightResponse['error']}";
              }

              // Update the LAST message
              setState(() {
                  _messages.last['content'] = "${_messages.last['content']}$finalInsight";
                  _isLoading = false; 
              });
              _scrollToBottom();
          }
          return;
      }

      // HANDLE OTHER MODES NORMAL WAY
      if (_selectedMode == 'create-table') {
        response = await _apiService.createTable(finalPrompt);
      } else if (_selectedMode == 'query') {
        response = await _apiService.query(finalPrompt);
      } else if (_selectedMode == 'command') {
        response = await _apiService.command(finalPrompt);
      } else if (_selectedMode == 'migrate') {
         final parts = finalPrompt.split(':');
         if (parts.length < 2) {
           response = {'error': "Para migração, use o formato: `nome_tabela: comando`"};
         } else {
           response = await _apiService.migrate(parts[0].trim(), parts.sublist(1).join(':').trim());
         }
      } else if (_selectedMode == 'free-chat') {
         // Streaming implementation
         final stream = _apiService.chatFreeStream(finalPrompt, contextId: _selectedContextId);
         
         // Create a placeholder message
         _messages.add({'role': 'assistant', 'content': ''});
         setState(() => _isLoading = false); // Stop loading indicator immediately as we stream
         
         String fullReply = "";
         String typingBuffer = "";
         await for (final chunk in stream) {
             if (chunk.isEmpty) continue;
             typingBuffer += chunk;

             // Flush when we reach a space/newline or a small batch size
             if (typingBuffer.contains(' ') || typingBuffer.contains('\n') || typingBuffer.length >= 12) {
                fullReply += typingBuffer;
                typingBuffer = "";
                setState(() {
                    _messages.last['content'] = fullReply;
                });
                _scrollToBottom();
                await Future.delayed(const Duration(milliseconds: 2));
             }
         }
         // Flush any remaining buffer
         if (typingBuffer.isNotEmpty) {
            fullReply += typingBuffer;
            setState(() {
                _messages.last['content'] = fullReply;
            });
            _scrollToBottom();
         }
         return; // Skip the rest of the logic
      } else {
        // Fallback (should not happen due to above if)
        response = {'error': 'Invalid mode'};
      }

      String botReply = '';
      if (response.containsKey('error')) {
        botReply = 'Erro: ${response['error']}';
      } else if (response.containsKey('insight')) {
        botReply = response['insight'];
      } else if (response.containsKey('sql_executed')) {
        botReply = "Comando executado com sucesso!\n```sql\n${response['sql_executed']}\n```";
        if (response.containsKey('warning') && response['warning'] != null) {
          botReply += "\n\n⚠️ **Aviso:** ${response['warning']}";
        }
      } else if (response.containsKey('results')) { // Query results
         final results = response['results'];
         botReply = "Encontrei ${results is List ? results.length : 0} resultados.\n```json\n${jsonEncode(results)}\n```";
      } else {
         botReply = jsonEncode(response);
      }

      await _typeAssistantReply(botReply);

    } catch (e) {
      setState(() {
        _messages.add({'role': 'assistant', 'content': 'Erro: $e'});
      });
    } finally {
      if (_isLoading) {
         setState(() => _isLoading = false);
      }
    }
  }

  void _scrollToBottom() {
      Future.delayed(const Duration(milliseconds: 100), () {
        if (_scrollController.hasClients) {
          _scrollController.animateTo(
            _scrollController.position.maxScrollExtent,
            duration: const Duration(milliseconds: 300),
            curve: Curves.easeOut,
          );
        }
      });
  }


  @override
  Widget build(BuildContext context) {
    final isMobile = MediaQuery.of(context).size.width < 800;

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: const Color(0xFF0F0F12), // Zinc 950
      drawer: isMobile ? Drawer(child: _buildSidebar(isMobile)) : null,
      body: Row(
        children: [
          // Sidebar
          if (!isMobile && _isSidebarOpen) _buildSidebar(isMobile),

          // Main Content
          Expanded(
            child: SafeArea(
              top: true,
              bottom: false,
              child: Column(
                children: [
                  _buildHeader(isMobile),
                  Expanded(
                    child: Container(
                      decoration: const BoxDecoration(
                        color: Color(0xFF0F0F12),
                        border: Border(top: BorderSide(color: Color(0xFF27272A), width: 1)),
                      ),
                      child: _buildMainContent(),
                    ),
                  ),
                  // Input Area - Now visible for both Chat and Explore
                  Container(
                    decoration: const BoxDecoration(
                       border: Border(top: BorderSide(color: Color(0xFF27272A), width: 1)),
                       color: Color(0xFF0F0F12),
                    ),
                    padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 24),
                    child: _buildInputArea(),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSidebar(bool isMobile) {
    return Container(
      width: 260,
      color: const Color(0xFF18181B), // Zinc 900
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Logo area
              Padding(
                padding: const EdgeInsets.only(bottom: 32, left: 8),
                child: Row(
                  children: [
                    Container(
                      width: 32, height: 32,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Icon(Icons.auto_awesome, color: Colors.black, size: 20),
                    ),
                    const SizedBox(width: 12),
                    Text(
                      'Power AI',
                      style: GoogleFonts.outfit(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
    
              // Navigation Items
              _buildNavItem(0, Icons.search, 'Search', isMobile),
              _buildNavItem(1, Icons.chat_bubble_outline, 'Chat', isMobile),
              _buildNavItem(2, Icons.explore_outlined, 'Explore', isMobile),
              _buildNavItem(3, Icons.library_books_outlined, 'Library', isMobile),
              _buildNavItem(4, Icons.layers_outlined, 'Contexts', isMobile),
    
              const Spacer(),
              
              _buildModeSelector(),
    
              const SizedBox(height: 24),
              _buildNavItem(99, Icons.settings_outlined, 'Settings', isMobile),
              const SizedBox(height: 8),
              
              // User profile dummy
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.white.withOpacity(0.05),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  children: [
                    const CircleAvatar(radius: 16, backgroundColor: Colors.grey),
                    const SizedBox(width: 12),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Usuário', style: GoogleFonts.inter(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w500)),
                        Text('Free Plan', style: GoogleFonts.inter(color: Colors.grey, fontSize: 11)),
                      ],
                    )
                  ],
                ),
              )
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildNavItem(int index, IconData icon, String label, bool isMobile) {
    final isSelected = _selectedNavIndex == index;
    return GestureDetector(
      onTap: () {
        setState(() => _selectedNavIndex = index);
        if (isMobile) Navigator.pop(context); // Close drawer
      },
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
        decoration: BoxDecoration(
          color: isSelected ? const Color(0xFF27272A) : Colors.transparent, // Zinc 800
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            Icon(icon, color: isSelected ? Colors.white : Colors.grey[500], size: 20),
            const SizedBox(width: 12),
            Text(
              label, 
              style: GoogleFonts.inter(
                color: isSelected ? Colors.white : Colors.grey[500],
                fontWeight: isSelected ? FontWeight.w500 : FontWeight.w400,
                fontSize: 14
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildModeSelector() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: const Color(0xFF27272A),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('MODE', style: GoogleFonts.inter(color: Colors.grey[600], fontSize: 10, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          DropdownButton<String>(
            value: _selectedMode,
            isExpanded: true,
            dropdownColor: const Color(0xFF27272A),
            style: GoogleFonts.inter(color: Colors.white, fontSize: 13),
            underline: Container(),
            icon: const Icon(Icons.keyboard_arrow_down, color: Colors.grey, size: 16),
            onChanged: (String? newValue) {
              setState(() {
                _selectedMode = newValue!;
              });
            },
            items: <String>['analyze', 'create-table', 'query', 'command', 'migrate', 'free-chat']
                .map<DropdownMenuItem<String>>((String value) {
              return DropdownMenuItem<String>(
                value: value,
                child: Text(value.toUpperCase().replaceAll('-', ' ')),
              );
            }).toList(),
          ),
        ],
      ),
    );
  }

  Widget _buildHeader(bool isMobile) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: Color(0xFF27272A))),
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  if (isMobile) ...[
                    IconButton(
                      icon: const Icon(Icons.menu, color: Colors.white70),
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                      onPressed: () => _scaffoldKey.currentState?.openDrawer(),
                    ),
                    const SizedBox(width: 16),
                  ],
                  Text(
                    'Chat', 
                    style: GoogleFonts.outfit(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w600)
                  ),
                  const SizedBox(width: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFF27272A),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      _selectedMode.toUpperCase().replaceAll('-', ' '), 
                      style: GoogleFonts.inter(color: Colors.grey[400], fontSize: 10, fontWeight: FontWeight.w600, letterSpacing: 0.5)
                    ),
                  ),
                ],
              ),
              Container(
                decoration: BoxDecoration(
                  color: const Color(0xFF27272A),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: IconButton(
                  onPressed: () {},
                  icon: const Icon(Icons.more_horiz, color: Colors.white70, size: 18),
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                ),
              )
            ],
          ),
          
          // Show selected tables context here instead of input area
          if (_selectedContextTables.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Container(
                 width: double.infinity,
                 padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                 decoration: BoxDecoration(
                   color: const Color(0xFF4F46E5).withOpacity(0.08),
                   borderRadius: BorderRadius.circular(8),
                   border: Border.all(color: const Color(0xFF4F46E5).withOpacity(0.2))
                 ),
                 child: Row(
                   children: [
                     const Icon(Icons.table_chart_outlined, size: 14, color: Color(0xFF818CF8)),
                     const SizedBox(width: 8),
                     Expanded(
                       child: Text(
                         "${_selectedContextTables.length} tables active for analysis", 
                         style: GoogleFonts.inter(color: const Color(0xFF818CF8), fontSize: 12, fontWeight: FontWeight.w500)
                       ),
                     ),
                     GestureDetector(
                       onTap: () => setState(() => _selectedContextTables = []),
                       child: const Icon(Icons.close, size: 16, color: Color(0xFF818CF8)),
                     )
                   ],
                 ),
               ),
            ),
        ],
      ),
    );
  }

  Widget _buildMessageBubble(String content, bool isUser) {
    return Align(
      alignment: isUser ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 24),
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.6),
        child: Column(
          crossAxisAlignment: isUser ? CrossAxisAlignment.end : CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (!isUser) ...[
                  Container(
                    width: 24, height: 24,
                    decoration: BoxDecoration(
                       gradient: const LinearGradient(colors: [Color(0xFF4F46E5), Color(0xFF9333EA)]),
                       borderRadius: BorderRadius.circular(6)
                    ),
                    child: const Icon(Icons.smart_toy, color: Colors.white, size: 14),
                  ),
                  const SizedBox(width: 12),
                ],
                Flexible(
                  child: Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: isUser ? const Color(0xFF3F3F46) : Colors.transparent, // Zinc 700 for user
                      borderRadius: BorderRadius.circular(16).copyWith(
                        bottomRight: isUser ? Radius.zero : const Radius.circular(16),
                        bottomLeft: !isUser ? Radius.zero : const Radius.circular(16)
                      ),
                      border: !isUser ? Border.all(color: Colors.white.withOpacity(0.1)) : null,
                    ),
                    child: isUser 
                      ? Text(content, style: GoogleFonts.inter(color: Colors.white, height: 1.5))
                      : MarkdownBody(
                          data: content, 
                          styleSheet: MarkdownStyleSheet(
                            p: GoogleFonts.inter(color: Colors.grey[300], height: 1.6),
                            code: GoogleFonts.inter(color: const Color(0xFFA5B4FC), backgroundColor: Colors.transparent),
                            codeblockDecoration: BoxDecoration(
                              color: const Color(0xFF18181B),
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: Colors.white.withOpacity(0.1))
                            ),
                          ),
                        ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _typeAssistantReply(String content) async {
    // If already typing, append to last assistant bubble
    if (_isTyping && _messages.isNotEmpty && _messages.last['role'] == 'assistant') {
      content = _messages.last['content']! + content;
      _messages.removeLast();
    }

    _isTyping = true;
    _messages.add({'role': 'assistant', 'content': ''});
    setState(() {});
    _scrollToBottom();

    String full = "";
    String buffer = "";
    for (int i = 0; i < content.length; i++) {
      final ch = content[i];
      buffer += ch;
      // Flush on space/newline or every ~18 chars to speed up
      if (ch == ' ' || ch == '\n' || buffer.length >= 18 || i == content.length - 1) {
        full += buffer;
        buffer = "";
        setState(() {
          _messages.last['content'] = full;
        });
        _scrollToBottom();
        await Future.delayed(const Duration(milliseconds: 2));
      }
    }

    _isTyping = false;
  }

  Widget _buildMainContent() {
    if (_selectedNavIndex == 4) { // Contexts
      return const ContextsScreen();
    }
    
    if (_selectedNavIndex == 2) { // Explore
      return ExploreScreen(
        onTableSelected: (tables) {
          setState(() => _selectedContextTables = tables);
        },
      );
    }
    
    // Chat or Default
    return Stack(
      children: [
        // Chat list
        ListView.builder(
          controller: _scrollController,
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 100),
          itemCount: _messages.length + (_isLoading ? 1 : 0),
          itemBuilder: (context, index) {
            if (index == _messages.length) {
              return const Align(
                alignment: Alignment.centerLeft,
                child: CustomLoadingWidget(),
              );
            }
            final msg = _messages[index];
            return _buildMessageBubble(msg['content']!, msg['role'] == 'user');
          },
        ),
      ],
    );
  }

  Widget _buildInputArea() {
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF18181B), // Zinc 900
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withOpacity(0.08)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.1),
            blurRadius: 4,
            offset: const Offset(0, 2),
          )
        ],
      ),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Row(
        children: [
          IconButton(
            onPressed: () {}, 
            icon: const Icon(Icons.add_circle_outline, color: Colors.grey),
            tooltip: 'Add Attachment',
          ),
          
          // Context Selector (DB stored)
          PopupMenuButton<int?>(
            tooltip: 'Select Context',
            icon: Icon(Icons.layers_outlined, color: _selectedContextId != null ? const Color(0xFF818CF8) : Colors.grey[400], size: 22),
            color: const Color(0xFF27272A),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            offset: const Offset(0, -120),
            onSelected: (val) {
              if (val == -1) {
                // Navigate to context screen
                setState(() => _selectedNavIndex = 4);
              } else {
                setState(() => _selectedContextId = val);
              }
            },
            itemBuilder: (context) {
              final list = <PopupMenuEntry<int?>>[
                PopupMenuItem<int?>(
                  value: null, 
                  child: Text('No Context', style: GoogleFonts.inter(color: Colors.white))
                ),
              ];
              for (var c in _availableContexts) {
                 list.add(PopupMenuItem<int?>(
                   value: c.id, 
                   child: Text(c.name, style: GoogleFonts.inter(color: Colors.white))
                 ));
              }
              list.add(const PopupMenuDivider(height: 1,));
              list.add(PopupMenuItem<int?>(
                value: -1, 
                child: Row(
                  children: [
                     const Icon(Icons.add, size: 16, color: Color(0xFF818CF8)),
                     const SizedBox(width: 8),
                     Text('Manage Contexts', style: GoogleFonts.inter(color: const Color(0xFF818CF8)))
                  ],
                )
              ));
              return list;
            },
          ),
          const SizedBox(width: 8),

          Expanded(
            child: TextField(
              controller: _controller,
              style: GoogleFonts.inter(color: Colors.white, fontSize: 14),
              maxLines: null,
              keyboardType: TextInputType.multiline,
              textInputAction: TextInputAction.send,
              decoration: InputDecoration(
                hintText: _selectedContextTables.isNotEmpty 
                  ? 'Perguntar sobre as tabelas selecionadas...' 
                  : 'Ask anything to AI...',
                hintStyle: GoogleFonts.inter(color: Colors.grey[600], fontSize: 14),
                border: InputBorder.none,
                contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                isDense: true,
              ),
              onSubmitted: (_) => _sendMessage(),
            ),
          ),
          
          const SizedBox(width: 8),
          
          Container(
            height: 36,
            width: 36,
            decoration: BoxDecoration(
              color: const Color(0xFF4F46E5), // Indigo 600
              borderRadius: BorderRadius.circular(10),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFF4F46E5).withOpacity(0.4),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                )
              ]
            ),
            child: IconButton(
              onPressed: _sendMessage,
              icon: const Icon(Icons.arrow_upward, color: Colors.white, size: 18),
              padding: EdgeInsets.zero,
              tooltip: 'Send Message',
            ),
          ),
          const SizedBox(width: 4),
        ],
      ),
    );
  }

  bool _sameTables(List<String> a, List<String> b) {
    if (a.length != b.length) return false;
    final sa = a.toSet();
    final sb = b.toSet();
    return sa.length == sb.length && sa.difference(sb).isEmpty;
  }
}
